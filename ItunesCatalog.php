<?php

namespace Omnisong\Itunes;

use Omnisong\Catalog\CatalogInterface;
use Omnisong\Exception\NotSupportedException;
use Omnisong\Exception\ProviderException;
use Omnisong\Exception\RateLimitedException;
use Omnisong\Exception\UnavailableException;
use Omnisong\Model\Label;
use Omnisong\Model\PlatformLink;
use Omnisong\Model\PlatformLinks;
use Omnisong\Model\Reference;
use Omnisong\Model\Release;
use Omnisong\Model\Track;
use Omnisong\Platform;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Apple's catalogue through the iTunes Search API (search and lookup, no
 * key): a release by its UPC, an ISRC, an Apple Music URL or its id, with
 * its tracks and their 30-second previews; an artist's albums. It gives no
 * ISRC and no composer, and only the Apple links - the others are Odesli's.
 */
final class ItunesCatalog implements CatalogInterface
{
    public const BASE_URI = 'https://itunes.apple.com/';

    /** The most a search answers with. */
    private const LIMIT = 200;

    /** @var array<string, Release|null> what was found already, by query: links() and release() ask the same thing */
    private array $found = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $country = 'us',
        private readonly string $baseUri = self::BASE_URI,
    ) {
    }

    public function getName(): string
    {
        return 'itunes';
    }

    public function links(Reference $reference): PlatformLinks
    {
        return $this->release($reference)?->links ?? PlatformLinks::none();
    }

    public function release(Reference $reference): ?Release
    {
        $query = match (true) {
            null !== $reference->upc => ['upc' => $reference->upc],
            null !== $reference->isrc => ['isrc' => $reference->isrc],
            null !== $reference->id && \in_array($reference->platform, [Platform::ITUNES, Platform::APPLE_MUSIC], true) => ['id' => $reference->id],
            null !== $reference->url && null !== ($id = self::idOf($reference->url)) => ['id' => $id],
            default => throw NotSupportedException::reference('itunes', $reference),
        };
        $store = ['entity' => 'song', 'country' => strtolower($reference->country ?? $this->country), 'limit' => self::LIMIT];
        $memo = http_build_query($query + $store);
        if (\array_key_exists($memo, $this->found)) {
            return $this->found[$memo];
        }

        $rows = $this->get('lookup', $query + $store);
        $collection = self::first($rows, 'collection');
        if (null === $collection && ($track = self::first($rows, 'track')) && isset($track['collectionId'])) {
            // A track's id, or an ISRC: the answer is the track alone; its album is asked next.
            $rows = $this->get('lookup', ['id' => $track['collectionId']] + $store);
            $collection = self::first($rows, 'collection');
        }
        if (null === $collection) {
            return $this->found[$memo] = null;
        }

        $tracks = [];
        foreach ($rows as $row) {
            if ('track' === ($row['wrapperType'] ?? null) && 'song' === ($row['kind'] ?? null) && ($row['collectionId'] ?? null) === $collection['collectionId']) {
                $tracks[] = new Track(
                    (string) ($row['trackName'] ?? ''),
                    isset($row['trackNumber']) ? (int) $row['trackNumber'] : null,
                    isset($row['discNumber']) ? (int) $row['discNumber'] : null,
                    isset($row['trackTimeMillis']) ? (int) round($row['trackTimeMillis'] / 1000) : null,
                    null,
                    isset($row['previewUrl']) ? (string) $row['previewUrl'] : null,
                    isset($row['artistName']) ? (string) $row['artistName'] : null,
                    null,
                    ['itunes' => (string) $row['trackId']],
                    isset($row['trackViewUrl']) ? self::clean((string) $row['trackViewUrl']) : null,
                );
            }
        }
        usort($tracks, static fn (Track $a, Track $b) => [$a->disc, $a->position] <=> [$b->disc, $b->position]);

        return $this->found[$memo] = self::hydrate($collection, $reference->upc)->withTracks($tracks);
    }

    public function releases(string $artist, int $limit = 50, ?string $country = null): array
    {
        $rows = $this->get('search', [
            'term' => $artist,
            'media' => 'music',
            'entity' => 'album',
            'attribute' => 'artistTerm',
            'country' => strtolower($country ?? $this->country),
            'limit' => max(1, min($limit, self::LIMIT)),
        ]);
        $releases = [];
        foreach ($rows as $row) {
            if ('collection' === ($row['wrapperType'] ?? null)) {
                $releases[] = self::hydrate($row);
            }
        }
        usort($releases, static fn (Release $a, Release $b) => $b->releasedAt <=> $a->releasedAt);

        return \array_slice($releases, 0, $limit);
    }

    /** @param array<string, mixed> $row a "collection" row */
    private static function hydrate(array $row, ?string $upc = null): Release
    {
        $title = (string) ($row['collectionName'] ?? '');
        $type = Release::ALBUM;
        // The store's own naming: "Title - Single", "Title - EP".
        if (preg_match('/^(.+) - (Single|EP)$/', $title, $m)) {
            [$title, $type] = [$m[1], 'EP' === $m[2] ? Release::EP : Release::SINGLE];
        }
        $id = (string) $row['collectionId'];
        $links = [];
        if (!empty($row['collectionViewUrl'])) {
            $url = self::clean((string) $row['collectionViewUrl']);
            $country = preg_match('#apple\.com/([a-z]{2})/#', $url, $m) ? strtoupper($m[1]) : null;
            $links[] = new PlatformLink(Platform::APPLE_MUSIC, $url, $id, null, $country);
            $links[] = new PlatformLink(Platform::ITUNES, $url.(str_contains($url, '?') ? '&' : '?').'app=itunes', $id, null, $country);
        }
        try {
            $releasedAt = empty($row['releaseDate']) ? null : new \DateTimeImmutable((string) $row['releaseDate']);
        } catch (\Exception) {
            $releasedAt = null;
        }

        return new Release(
            $title,
            isset($row['artistName']) ? (string) $row['artistName'] : null,
            self::label($row['copyright'] ?? null),
            $upc,
            $releasedAt,
            isset($row['artworkUrl100']) ? str_replace('100x100bb', '1200x1200bb', (string) $row['artworkUrl100']) : null,
            $type,
            [],
            new PlatformLinks($links),
            isset($row['primaryGenreName']) ? (string) $row['primaryGenreName'] : null,
            isset($row['trackCount']) ? (int) $row['trackCount'] : null,
            ['itunes' => $id, 'apple_music' => $id],
            isset($row['copyright']) ? (string) $row['copyright'] : null,
        );
    }

    /** "℗ 2025 ES-DUR" names the label; a line without ℗ or © names nobody. */
    private static function label(mixed $copyright): ?Label
    {
        if (!\is_string($copyright) || !preg_match('/[℗©]\s*(?:(?:19|20)\d{2}[\s,\/&-]*)*(.+?)\s*$/um', $copyright, $m)) {
            return null;
        }
        $name = trim(preg_replace('/\s+(?:19|20)\d{2}$/', '', $m[1]) ?? $m[1], " \t,;");

        return '' !== $name ? new Label($name) : null;
    }

    /** The album's id in an Apple Music / iTunes URL (…/album/name/1793146044?i=…, …/id1793146044); null for any other URL. */
    private static function idOf(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        if (!str_ends_with($host, 'apple.com')) {
            return null;
        }

        return preg_match('#/(?:id)?(\d{5,})/?$#', (string) parse_url($url, \PHP_URL_PATH), $m) ? $m[1] : null;
    }

    /** A view URL without its tracking parameter (uo). */
    private static function clean(string $url): string
    {
        $url = preg_replace('/([?&])uo=\d+(&|$)/', '$1', $url) ?? $url;

        return rtrim($url, '?&');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    private static function first(array $rows, string $wrapperType): ?array
    {
        foreach ($rows as $row) {
            if ($wrapperType === ($row['wrapperType'] ?? null)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return list<array<string, mixed>> the "results" rows
     *
     * @throws UnavailableException unreachable, down, or told to slow down (403, 429)
     */
    private function get(string $path, array $query): array
    {
        try {
            $response = $this->http->request('GET', rtrim($this->baseUri, '/').'/'.$path, ['query' => $query]);
            $status = $response->getStatusCode();
            // Served as text/javascript: read as JSON whatever the header says.
            $body = $response->getContent(false);
            $headers = $response->getHeaders(false);
        } catch (TransportExceptionInterface $e) {
            throw new UnavailableException('itunes', $e->getMessage(), null, $e);
        }
        if (403 === $status || 429 === $status) {
            throw new RateLimitedException('itunes', isset($headers['retry-after'][0]) ? (int) $headers['retry-after'][0] : null);
        }
        if (404 === $status) {
            return [];
        }
        if ($status >= 500) {
            throw new UnavailableException('itunes', \sprintf('HTTP %d', $status), $status);
        }
        $answer = json_decode($body, true);
        if ($status >= 400) {
            throw new ProviderException('itunes', \sprintf('HTTP %d%s', $status, \is_array($answer) && isset($answer['errorMessage']) ? ' '.$answer['errorMessage'] : ''), $status);
        }
        if (!\is_array($answer)) {
            throw new UnavailableException('itunes', 'an answer that is not JSON', $status);
        }

        return array_values(array_filter((array) ($answer['results'] ?? []), 'is_array'));
    }
}
