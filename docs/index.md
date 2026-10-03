# omnischolar/crossref

## Installation

```sh
composer require omnischolar/crossref
```

```php
use Omnischolar\Crossref\CrossrefSourceFactory;

$crossref = (new CrossrefSourceFactory($httpClient))->create(['mailto' => 'support@glitchr.io']);
```

The `mailto` is sent as a parameter and in the User-Agent: Crossref's polite pool (3 calls a
second, as of 2026).

## Calls

| Method | Reads | Crossref |
|---|---|---|
| `work()` | DOI | `works/{doi}` |
| `works()` | ORCID; or a name | `works?filter=orcid:`; `works?query.author=` by relevance, kept when an author has the family name and the first given name (or its initial, when the work gives initials only) |
| `search()` | `text` and `title` (`query.bibliographic`), `author` (`query.author`) | `works` |
| `author()` | - | not supported: Crossref has no author profiles |

`Query`: `from` (`from-pub-date`), `to` (`until-pub-date`), `types` (Crossref types:
`journal-article`, `book`, `monograph`, `edited-book`, `book-chapter`, `proceedings-article`,
`posted-content`, `dissertation`, `report`...), `sort` (`newest`/`oldest` by `published`,
`cited` by `is-referenced-by-count`, `relevance` by `score`), `limit` (1 000 at most), `cursor`
(an offset: Crossref's cursors cannot sort by date). `domains` is refused.

## What a work holds

Title and subtitle, type (`posted-content` is a preprint), authors and editors (given and family
names, ORCID, affiliations), year and date from `issued`, venue (container title, its
abbreviation, ISSNs, publisher; a conference's event name), publisher, volume, issue, pages,
abstract (JATS tags stripped), the publisher's landing page, licence, citation count
(`is-referenced-by-count`), subjects as keywords; identifiers: DOI and every ISBN (print and
electronic).

## What Crossref does not give

No author profiles, no open-access status. Works by name rest on a fuzzy author query: they come
by relevance, filtered here; a name on many works by other people with the same family name and
first name cannot be told apart. Works by ORCID are those whose publisher deposited the ORCID
(some placeholder ORCIDs are used by thousands of unrelated works). Offsets stop at 10 000.

## Tests

Recorded on 2026-10-04: a work by DOI (10.1039/d4sc04973j), the eight works deposited with Juan
Maldacena's ORCID, the answer to `query.author=Monica Neagoy` (four of hers among others).
