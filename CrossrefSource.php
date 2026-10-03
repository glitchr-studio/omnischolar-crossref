<?php

namespace Omnischolar\Crossref;

use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Merger;
use Omnischolar\Model\Contributor;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Identifiers;
use Omnischolar\Model\Scheme;
use Omnischolar\Model\Venue;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use Omnischolar\Source\Capability;
use Omnischolar\Source\HttpSource;
use Omnischolar\Source\Page;
use Omnischolar\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Crossref (api.crossref.org, no key): the reference metadata of any work
 * with a Crossref DOI - title, authors and their ORCIDs, journal or book,
 * volume, pages, publisher, ISBNs, licence, the citation count Crossref
 * knows - the works an ORCID was deposited with, and a bibliographic search.
 *
 * Crossref has no author profiles. The mailto puts the calls in its
 * "polite" pool (3 a second, as of 2026); without it, the public pool.
 */
final class CrossrefSource extends HttpSource
{
    public const BASE_URI = 'https://api.crossref.org/';

    /** The most rows Crossref serves per page. */
    public const MAX_ROWS = 1000;

    private const SELECT = 'DOI,type,title,subtitle,author,editor,issued,published,container-title,short-container-title,publisher,volume,issue,page,ISSN,ISBN,abstract,license,resource,URL,is-referenced-by-count,subject,event';

    /** The deepest offset Crossref pages to (its cursors do not sort by date). */
    public const MAX_OFFSET = 10000;

    /** Crossref's types for each kind. */
    private const TYPES = [
        'article' => ['journal-article'],
        'book' => ['book', 'monograph', 'edited-book', 'reference-book', 'proceedings'],
        'chapter' => ['book-chapter', 'book-section', 'book-part', 'reference-entry'],
        'conference' => ['proceedings-article'],
        'preprint' => ['posted-content'],
        'thesis' => ['dissertation'],
        'report' => ['report', 'report-component', 'standard'],
        'dataset' => ['dataset'],
        'software' => [],
        'review' => ['peer-review'],
        'editorial' => [],
        'other' => ['other', 'component'],
    ];

    public function __construct(
        HttpClientInterface $http,
        private readonly ?string $mailto = null,
        string $baseUri = self::BASE_URI,
        array $headers = [],
    ) {
        parent::__construct($http, $baseUri, $headers);
    }

    public function getName(): string
    {
        return 'crossref';
    }

    public function capabilities(): array
    {
        return [Capability::WORKS, Capability::WORK, Capability::SEARCH, Capability::CITATIONS, Capability::ABSTRACTS];
    }

    /**
     * The works deposited with an ORCID; or, by a name, those whose authors
     * include that name (Crossref's author query is fuzzy: its answers are
     * kept only when one of their authors has the family name and the
     * first initial asked, and they come by relevance).
     */
    public function works(Identifier|string $author, ?Query $query = null): Page
    {
        $query ??= new Query();
        $orcid = self::identify($author, Scheme::ORCID);
        if (null !== $orcid) {
            return $this->list($query, ['orcid:'.$orcid->value]);
        }
        if ($author instanceof Identifier) {
            throw NotSupportedException::identifier('crossref', (string) $author, 'list the works of');
        }
        $page = $this->list($query->with(['sort' => Query::RELEVANCE]), [], ['query.author' => $author]);
        $wanted = Contributor::fromName($author);
        $works = array_values(array_filter($page->works, static function (Work $work) use ($wanted): bool {
            foreach ($work->authors as $contributor) {
                if (self::sameName($wanted, $contributor)) {
                    return true;
                }
            }

            return false;
        }));

        return new Page($works, $works ? $page->next : null, null);
    }

    /**
     * The same family name, and the same first given name - or its initial,
     * when the work gives initials only ("K. Nakatani", not "Kenji Nakatani").
     */
    private static function sameName(Contributor $wanted, Contributor $contributor): bool
    {
        if (Merger::fingerprint($wanted->familyName()) !== Merger::fingerprint($contributor->familyName())) {
            return false;
        }
        $want = explode(' ', Merger::fingerprint((string) $wanted->given));
        if ('' === $want[0]) {
            return true;
        }
        $given = explode(' ', Merger::fingerprint((string) $contributor->given));
        if ('' === $given[0]) {
            return false;
        }
        $initials = [] === array_filter($given, static fn (string $part) => \strlen($part) > 1);

        return $initials ? $given[0] === $want[0][0] : $given[0] === $want[0];
    }

    public function work(Identifier|string $id): ?Work
    {
        $doi = self::identify($id, Scheme::DOI) ?? throw NotSupportedException::identifier('crossref', (string) $id);
        $data = $this->getJson('works/'.$doi->value, ['mailto' => $this->mailto]);

        return isset($data['message']) ? $this->toWork($data['message']) : null;
    }

    public function search(Query $query): Page
    {
        $parameters = [];
        if (null !== $query->text || null !== $query->title) {
            $parameters['query.bibliographic'] = trim(($query->title ?? '').' '.($query->text ?? ''));
        }
        if (null !== $query->author) {
            $parameters['query.author'] = $query->author;
        }

        return $this->list($query, [], $parameters);
    }

    /**
     * @param list<string>         $filters
     * @param array<string, mixed> $parameters
     */
    private function list(Query $query, array $filters, array $parameters = []): Page
    {
        if ($query->domains) {
            throw NotSupportedException::operation('crossref', 'filter by domain');
        }
        if (null !== $query->from) {
            $filters[] = 'from-pub-date:'.$query->from;
        }
        if (null !== $query->to) {
            $filters[] = 'until-pub-date:'.$query->to.'-12-31';
        }
        foreach ($query->types as $type) {
            foreach (self::TYPES[$type->value] as $name) {
                $filters[] = 'type:'.$name;
            }
        }
        [$sort, $order] = match ($query->sort) {
            Query::OLDEST => ['published', 'asc'],
            Query::CITED => ['is-referenced-by-count', 'desc'],
            Query::RELEVANCE => ['score', 'desc'],
            default => ['published', 'desc'],
        };
        $rows = max(1, min(self::MAX_ROWS, $query->limit));
        $offset = max(0, (int) $query->cursor);
        $data = $this->getJson('works', $parameters + [
            'filter' => $filters ? implode(',', $filters) : null,
            'rows' => $rows,
            'offset' => $offset ?: null,
            'sort' => $sort,
            'order' => $order,
            'select' => self::SELECT,
            'mailto' => $this->mailto,
        ]);
        $message = $data['message'] ?? [];
        $works = array_map($this->toWork(...), $message['items'] ?? []);

        $total = $message['total-results'] ?? null;
        $next = $offset + $rows;
        $more = $works && (null === $total || $next < $total) && $next < self::MAX_OFFSET;

        return new Page($works, $more ? (string) $next : null, $total);
    }

    /** @param array<string, mixed> $m */
    private function toWork(array $m): Work
    {
        $crossrefType = (string) ($m['type'] ?? 'other');
        $type = WorkType::fromName($crossrefType);
        if ('posted-content' === $crossrefType && isset($m['subtype']) && 'preprint' !== $m['subtype']) {
            $type = WorkType::OTHER;
        }

        $contributors = [];
        foreach (['author' => Contributor::AUTHOR, 'editor' => Contributor::EDITOR] as $field => $role) {
            foreach ($m[$field] ?? [] as $person) {
                $given = $person['given'] ?? null;
                $family = $person['family'] ?? null;
                $name = null !== $family ? trim(($given ?? '').' '.$family) : (string) ($person['name'] ?? '');
                $contributors[] = new Contributor(
                    $name,
                    $given,
                    $family,
                    Identifiers::of(Identifier::tryOf(Scheme::ORCID, $person['ORCID'] ?? null)),
                    $role,
                    array_values(array_filter(array_map(static fn (array $a) => $a['name'] ?? null, $person['affiliation'] ?? []))),
                );
            }
        }

        $parts = $m['issued']['date-parts'][0] ?? $m['published']['date-parts'][0] ?? [];
        $year = isset($parts[0]) && null !== $parts[0] ? (int) $parts[0] : null;

        $container = $m['container-title'][0] ?? null;
        $venue = null;
        if (null !== $container || isset($m['event']['name'])) {
            $venue = new Venue(
                name: self::text((string) ($container ?? $m['event']['name'])) ?? '',
                type: match ($type) {
                    WorkType::CHAPTER => Venue::BOOK,
                    WorkType::CONFERENCE => Venue::CONFERENCE,
                    WorkType::BOOK => Venue::BOOK_SERIES,
                    WorkType::PREPRINT => Venue::REPOSITORY,
                    default => Venue::JOURNAL,
                },
                issn: array_values(array_unique(array_filter(array_map(static fn ($i) => Identifier::tryOf(Scheme::ISSN, (string) $i)?->value, $m['ISSN'] ?? [])))),
                publisher: self::text($m['publisher'] ?? null),
                abbreviation: self::text($m['short-container-title'][0] ?? null),
            );
        }

        $identifiers = [Identifier::tryOf(Scheme::DOI, $m['DOI'] ?? null)];
        foreach ($m['isbn-type'] ?? [] as $isbn) {
            $identifiers[] = Identifier::tryOf(Scheme::ISBN, (string) ($isbn['value'] ?? ''));
        }
        foreach ($m['ISBN'] ?? [] as $isbn) {
            $identifiers[] = Identifier::tryOf(Scheme::ISBN, (string) $isbn);
        }

        return new Work(
            title: self::text($m['title'][0] ?? null) ?? '',
            type: $type,
            authors: $contributors,
            year: $year,
            date: self::date($parts[0] ?? null, $parts[1] ?? null, $parts[2] ?? null),
            subtitle: self::text($m['subtitle'][0] ?? null),
            venue: $venue,
            publisher: self::text($m['publisher'] ?? null),
            volume: $m['volume'] ?? null,
            issue: $m['issue'] ?? null,
            pages: $m['page'] ?? null,
            edition: isset($m['edition-number']) ? (string) $m['edition-number'] : null,
            abstract: self::text($m['abstract'] ?? null),
            language: $m['language'] ?? null,
            url: $m['resource']['primary']['URL'] ?? $m['URL'] ?? null,
            license: $m['license'][0]['URL'] ?? null,
            citations: $m['is-referenced-by-count'] ?? null,
            keywords: array_values(array_map('strval', $m['subject'] ?? [])),
            identifiers: new Identifiers($identifiers),
            source: 'crossref',
        );
    }
}
