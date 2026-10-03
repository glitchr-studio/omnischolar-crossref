# omnischolar/crossref

Crossref for [glitchr/omnischolar](https://github.com/glitchr-studio/omnischolar): the reference
metadata of any work with a Crossref DOI - title, authors and their ORCIDs, journal or book,
volume, pages, publisher, ISBNs, licence, the citation count Crossref knows - the works deposited
with an ORCID, the works signed with a name, a bibliographic search. No key; with a `mailto`,
the calls go to Crossref's polite pool.

```php
$crossref = (new CrossrefSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$crossref->work('10.1039/d4sc04973j');
$crossref->works('0000-0002-9127-1687');       // deposited with that ORCID
$crossref->works('Monica Neagoy');             // signed with that name
```

```yaml
omnischolar:
    sources:
        crossref: { factory: crossref, options: { mailto: 'support@glitchr.io' } }
```

The client is written directly on Symfony's HttpClient: the API is plain JSON, and a client
library (renanbr/crossref-client) would add a dependency without removing code.

See [docs/](docs/index.md).

License: LGPL-3.0-or-later.
