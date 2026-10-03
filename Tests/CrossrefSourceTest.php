<?php

namespace Omnischolar\Crossref\Tests;

use Omnischolar\Crossref\CrossrefSourceFactory;
use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Exception\ProviderException;
use Omnischolar\Model\Contributor;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Scheme;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use Omnischolar\Source\Query;
use Omnischolar\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from api.crossref.org on 2026-10-04: work-doi.json
 * (works/10.1039/d4sc04973j), works-orcid.json (works?filter=orcid:0000-0002-9127-1687,
 * Juan Maldacena, newest first), works-name.json (works?query.author=Monica Neagoy,
 * by relevance: four of hers, then other Neagoys and Monicas).
 */
final class CrossrefSourceTest extends TestCase
{
    /** @var list<array{string, array<string, string>, array<string, list<string>>}> */
    private array $calls = [];

    private function source(?MockResponse $response = null): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $path = rawurldecode((string) parse_url($url, \PHP_URL_PATH));
            $this->calls[] = [$path, $query, $options['normalized_headers'] ?? []];
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                '/works/10.1039/d4sc04973j' === $path => 'work-doi.json',
                '/works' === $path && 'orcid:0000-0002-9127-1687' === ($query['filter'] ?? null) => 'works-orcid.json',
                '/works' === $path && 'Monica Neagoy' === ($query['query_author'] ?? $query['query.author'] ?? null) => 'works-name.json',
                default => null,
            };

            return $fixture ? new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture)) : new MockResponse('Resource not found.', ['http_code' => 404]);
        });

        return (new CrossrefSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
    }

    public function testTheReferenceMetadataOfADoi(): void
    {
        $work = $this->source()->work('doi:10.1039/D4SC04973J');

        self::assertSame('Acid-sensitive photoswitches: towards catalytic on-demand release of stored light energy', $work->title);
        self::assertSame(WorkType::ARTICLE, $work->type);
        self::assertSame('Chemical Science', $work->venue->name);
        self::assertSame('Chem. Sci.', $work->venue->abbreviation);
        self::assertSame(['2041-6520', '2041-6539'], $work->venue->issn);
        self::assertSame('Royal Society of Chemistry (RSC)', $work->publisher);
        self::assertSame(2024, $work->year);
        self::assertSame('2024', $work->date);
        self::assertSame(['15', '39', '16034-16039'], [$work->volume, $work->issue, $work->pages]);
        self::assertSame('http://creativecommons.org/licenses/by/3.0/', $work->license);
        self::assertStringStartsWith('Molecular solar thermal energy storage', $work->abstract, 'the JATS tags stripped');
        self::assertSame('Chocron', $work->authors[0]->family);
        self::assertSame('0000-0003-4743-5151', $work->authors[1]->orcid());
        self::assertContains('Nakatani', array_map(static fn (Contributor $c) => $c->family, $work->authors));
        self::assertSame('crossref', $work->source);

        [, $query, $headers] = $this->calls[0];
        self::assertSame('support@glitchr.io', $query['mailto']);
        self::assertStringContainsString('mailto:support@glitchr.io', $headers['user-agent'][0]);
    }

    public function testTheWorksDepositedWithAnOrcid(): void
    {
        $page = $this->source()->works(Identifier::orcid('0000-0002-9127-1687'), new Query(limit: 10));

        self::assertSame(8, $page->total);
        self::assertCount(8, $page);
        self::assertTrue($page->isLast());
        self::assertSame('Real observers solving some imaginary problems', $page->works[0]->title, 'newest first');
        foreach ($page as $work) {
            self::assertContains('0000-0002-9127-1687', array_map(static fn (Contributor $c) => $c->orcid(), $work->authors));
        }
        [, $query] = $this->calls[0];
        self::assertSame('published', $query['sort']);
        self::assertSame('desc', $query['order']);
        self::assertArrayNotHasKey('offset', $query);
    }

    public function testByNameOnlyTheWorksSignedWithIt(): void
    {
        $works = $this->source()->works('Monica Neagoy', new Query(limit: 20))->works;

        self::assertSame(['10.4135/9781544308616', '10.4135/9781483394657', '10.3917/cape.573.0026', '10.3917/oj.scien.2024.01.0131'], array_map(static fn (Work $w) => $w->doi(), $works), 'not D. R. Neagoy, not the other Monicas');
        $book = $works[0];
        self::assertSame(WorkType::BOOK, $book->type);
        self::assertSame('Planting the Seeds of Algebra, PreK-2: Explorations for the Early Grades', $book->title);
        self::assertSame(['9781412996600', '9781544308616'], $book->isbns(), 'print and electronic');
        self::assertSame('score', $this->calls[0][1]['sort'], 'a fuzzy query, by relevance');
    }

    public function testANameIsMatchedByItsFirstGivenNameOrItsInitial(): void
    {
        $item = static fn (string $given, string $doi) => ['DOI' => $doi, 'type' => 'journal-article', 'title' => ['T'], 'author' => [['given' => $given, 'family' => 'Nakatani']], 'container-title' => ['ACS Applied Materials &amp; Interfaces']];
        $body = json_encode(['status' => 'ok', 'message' => ['total-results' => 4, 'items' => [
            $item('Keitaro', '10.1234/a'), $item('K.', '10.1234/b'), $item('Kenji', '10.1234/c'), $item('Kazuhiro K.', '10.1234/d'),
        ]]]);
        $works = $this->source(new MockResponse((string) $body))->works('Keitaro Nakatani')->works;

        self::assertSame(['10.1234/a', '10.1234/b'], array_map(static fn (Work $w) => $w->doi(), $works));
        self::assertSame('ACS Applied Materials & Interfaces', $works[0]->venue->name, 'the entity Crossref leaves decoded');
    }

    public function testFiltersAndPages(): void
    {
        $page = $this->source(new MockResponse('{"status":"ok","message":{"total-results":250,"items":[{"DOI":"10.1/x","type":"book-chapter","title":["A chapter"],"container-title":["A book"],"ISBN":["9781412996600"]}]}}'))
            ->search(new Query(text: 'photochromism', author: 'Nakatani', from: 2020, to: 2024, types: [WorkType::CHAPTER], limit: 100, cursor: '100'));

        [, $query] = $this->calls[0];
        self::assertSame('from-pub-date:2020,until-pub-date:2024-12-31,type:book-chapter,type:book-section,type:book-part,type:reference-entry', $query['filter']);
        self::assertSame('photochromism', $query['query_bibliographic'] ?? $query['query.bibliographic']);
        self::assertSame('100', $query['offset']);
        self::assertSame('200', $page->next, 'an offset: Crossref\'s cursors do not sort by date');
        self::assertSame(WorkType::CHAPTER, $page->works[0]->type);
        self::assertSame('A book', $page->works[0]->venue->name);
    }

    public function testWhatCrossrefDoesNot(): void
    {
        $source = $this->source();
        self::assertNull($source->work('10.1039/unknown'));
        try {
            $source->author('0000-0002-9127-1687');
            self::fail('no author profiles');
        } catch (NotSupportedException) {
        }
        $this->expectException(ProviderException::class);
        $this->source(new MockResponse('{"status":"failed"}', ['http_code' => 400]))->search(new Query(text: 'x'));
    }
}
