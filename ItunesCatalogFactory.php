<?php

namespace Omnisong\Itunes;

use Omnisong\Catalog\CatalogFactory;
use Omnisong\Catalog\CatalogInterface;
use Omnisong\Config;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The iTunes Search API: an artist's albums, their tracks with the
 * 30-second previews, the artwork - no key.
 *
 *   options:
 *     country: 'de'                            # the store asked (ISO 3166-1 alpha-2, default us): what is sold there, in its language
 *     base_uri: 'https://itunes.apple.com/'
 */
final class ItunesCatalogFactory extends CatalogFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnisong.factory_name' => 'itunes',
            'omnisong.required_options' => [],
            'country' => 'us',
            'base_uri' => ItunesCatalog::BASE_URI,
        ]);
    }

    protected function build(Config $c): CatalogInterface
    {
        return new ItunesCatalog($this->http ?? HttpClient::create(), (string) ($c['country'] ?: 'us'), (string) ($c['base_uri'] ?: ItunesCatalog::BASE_URI));
    }
}
