<?php

namespace Omnischolar\Crossref;

use Omnischolar\Config;
use Omnischolar\Source\SourceFactory;
use Omnischolar\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Crossref: reference metadata by DOI, works by ORCID, bibliographic search - no key.
 *
 *   options:
 *     mailto: 'support@glitchr.io'           # sent with every call and in the User-Agent: Crossref's polite pool
 *     base_uri: 'https://api.crossref.org/'
 */
final class CrossrefSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnischolar.factory_name' => 'crossref',
            'omnischolar.required_options' => [],
            'base_uri' => CrossrefSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new CrossrefSource(
            $this->http ?? HttpClient::create(),
            $c->get('mailto'),
            (string) $c->get('base_uri', CrossrefSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
        );
    }
}
