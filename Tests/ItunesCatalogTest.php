<?php

namespace Omnisong\Itunes\Tests;

use Omnisong\Exception\NotSupportedException;
use Omnisong\Exception\ProviderException;
use Omnisong\Exception\RateLimitedException;
use Omnisong\Exception\UnavailableException;
use Omnisong\Itunes\ItunesCatalogFactory;
use Omnisong\Model\Reference;
use Omnisong\Model\Release;
use Omnisong\Platform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from the API on 2026-10-03, store "de":
 * lookup-upc.json (lookup?upc=4015372820954&entity=song, the same answer as
 * lookup?id=1793146044), lookup-track.json (lookup?id=1793146055: a track
 * alone), search-albums.json (search?term=Brieuc+Vourch&entity=album&attribute=artistTerm).
 */
final class ItunesCatalogTest extends TestCase
{
    private const UPC = '4015372820954';
    private const EMPTY = "\n\n\n{\n \"resultCount\":0,\n \"results\": []\n}\n\n\n";

    /** @var list<array{string, array<string, string>}> the path and query of each call */
    private array $calls = [];

    /** @param array<string, mixed> $options */
    private function catalog(?MockResponse $response = null, array $options = ['country' => 'de']): \Omnisong\Catalog\CatalogInterface
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$path, $query];
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                '/search' === $path && 'Brieuc Vourch' === $query['term'] => 'search-albums.json',
                '/lookup' === $path && self::UPC === ($query['upc'] ?? null), '/lookup' === $path && '1793146044' === ($query['id'] ?? null) => 'lookup-upc.json',
                '/lookup' === $path && ('1793146055' === ($query['id'] ?? null) || isset($query['isrc'])) => 'lookup-track.json',
                default => null,
            };

            // As Apple serves it: JSON under a text/javascript content type.
            return new MockResponse($fixture ? (string) file_get_contents(__DIR__.'/Fixtures/'.$fixture) : self::EMPTY, ['response_headers' => ['content-type' => 'text/javascript; charset=utf-8']]);
        });

        return (new ItunesCatalogFactory($http))->create($options);
    }

    public function testAUpcIsTheReleaseWithItsTracksAndTheirPreviews(): void
    {
        $release = $this->catalog()->release(Reference::upc(self::UPC));

        self::assertSame('Perspectives Concertantes', $release->title);
        self::assertStringStartsWith('Anaelle Tourret, NDR Elbphilharmonie Orchester', $release->artist);
        self::assertSame(self::UPC, $release->upc);
        self::assertSame(Release::ALBUM, $release->type);
        self::assertSame('2025-02-28', $release->releasedAt->format('Y-m-d'));
        self::assertSame('℗ 2025 C2 Hamburg. Musik & Medienproduktion', $release->copyright);
        self::assertSame('C2 Hamburg. Musik & Medienproduktion', $release->label->name, 'who the ℗ line names');
        self::assertNull($release->label->url, 'the API knows no address for it');
        self::assertStringEndsWith('/cover.jpg/1200x1200bb.jpg', $release->coverUrl);
        self::assertSame('Klassik', $release->genre);
        self::assertSame(8, $release->trackCount);
        self::assertSame(['itunes' => '1793146044', 'apple_music' => '1793146044'], $release->ids);
        self::assertSame('https://music.apple.com/de/album/perspectives-concertantes/1793146044', $release->links->get(Platform::APPLE_MUSIC)->url, 'without ?uo=4');
        self::assertSame('1793146044', $release->links->get(Platform::APPLE_MUSIC)->id);
        self::assertSame('DE', $release->links->get(Platform::APPLE_MUSIC)->country);

        self::assertCount(8, $release->tracks);
        self::assertSame(range(1, 8), array_map(static fn ($t) => $t->position, $release->tracks));
        $first = $release->tracks[0];
        self::assertSame('Concerto for Harp and Orchestra in E-Flat Major, Op. 74: I. Allegro Moderato', $first->title);
        self::assertSame(1, $first->disc);
        self::assertSame(677, $first->duration, 'seconds');
        self::assertNull($first->isrc, 'not given by the Search API');
        self::assertNull($first->composer);
        self::assertSame('Anaelle Tourret, NDR Elbphilharmonie Orchester & Vasily Petrenko', $first->artist);
        self::assertSame(['itunes' => '1793146055'], $first->ids);
        self::assertSame('https://music.apple.com/de/album/concerto-for-harp-and-orchestra-in-e-flat-major-op-74/1793146044?i=1793146055', $first->url);
        foreach ($release->tracks as $track) {
            self::assertStringStartsWith('https://audio-ssl.itunes.apple.com/', (string) $track->previewUrl);
        }

        self::assertSame(['/lookup', ['upc' => self::UPC, 'entity' => 'song', 'country' => 'de', 'limit' => '200']], $this->calls[0]);
    }

    public function testAnAppleMusicUrlOrItsIdIsTheSameRelease(): void
    {
        $catalog = $this->catalog();
        $references = [
            Reference::url('https://music.apple.com/de/album/perspectives-concertantes/1793146044?uo=4'),
            Reference::url('https://geo.music.apple.com/de/album/_/1793146044?mt=1&app=music&ls=1'),
            Reference::id(Platform::ITUNES, '1793146044'),
            Reference::id(Platform::APPLE_MUSIC, '1793146044'),
        ];
        foreach ($references as $reference) {
            $release = $catalog->release($reference);
            self::assertSame('Perspectives Concertantes', $release->title);
            self::assertCount(8, $release->tracks);
            self::assertNull($release->upc, 'only a UPC asked for is known: the API does not give it');
        }
        self::assertCount(1, $this->calls, 'the same question is asked once');
        self::assertSame('1793146044', $this->calls[0][1]['id']);
    }

    public function testATracksIdOrAnIsrcLeadsToItsAlbum(): void
    {
        $catalog = $this->catalog();
        $release = $catalog->release(Reference::id(Platform::ITUNES, '1793146055', Reference::SONG));

        self::assertSame('Perspectives Concertantes', $release->title);
        self::assertCount(8, $release->tracks);
        self::assertSame(['1793146055', '1793146044'], array_map(static fn ($c) => $c[1]['id'], $this->calls), 'the track, then its collection');

        $this->calls = [];
        self::assertCount(8, $catalog->release(Reference::isrc('DE-AR4-25-00001'))->tracks);
        self::assertSame('DEAR42500001', $this->calls[0][1]['isrc']);
        self::assertArrayNotHasKey('isrc', $this->calls[1][1]);
    }

    public function testTheLinksAreApples(): void
    {
        $catalog = $this->catalog();
        $links = $catalog->links(Reference::upc(self::UPC));

        self::assertSame(['apple_music', 'itunes'], array_keys($links->toArray()));
        self::assertSame('https://music.apple.com/de/album/perspectives-concertantes/1793146044?app=itunes', $links->get(Platform::ITUNES)->url);
        self::assertCount(0, $catalog->links(Reference::upc('4000000000001')), 'unknown: none');
        self::assertNull($catalog->release(Reference::upc('4000000000001')));
    }

    public function testAnArtistsAlbumsNewestFirst(): void
    {
        $releases = $this->catalog()->releases('Brieuc Vourch', 10);

        self::assertCount(1, $releases);
        self::assertSame('Strauss & Franck: Sonatas for Violin and Piano', $releases[0]->title);
        self::assertSame('Brieuc Vourch & Guillaume Vincent', $releases[0]->artist);
        self::assertSame('FARAO classics', $releases[0]->label->name);
        self::assertSame('2021', $releases[0]->releasedAt->format('Y'));
        self::assertSame([], $releases[0]->tracks);
        self::assertSame(['/search', ['term' => 'Brieuc Vourch', 'media' => 'music', 'entity' => 'album', 'attribute' => 'artistTerm', 'country' => 'de', 'limit' => '10']], $this->calls[0]);

        $rows = [
            ['wrapperType' => 'collection', 'collectionId' => 1, 'collectionName' => 'Older', 'releaseDate' => '2019-01-14T08:00:00Z', 'copyright' => '℗ Shaboom 2016'],
            ['wrapperType' => 'collection', 'collectionId' => 2, 'collectionName' => 'Newer - Single', 'releaseDate' => '2024-05-01T07:00:00Z', 'copyright' => 'All rights reserved'],
            ['wrapperType' => 'collection', 'collectionId' => 3, 'collectionName' => 'Between - EP', 'releaseDate' => '2021-05-01T07:00:00Z', 'copyright' => '℗ 2014 2017 Shifting Sounds'],
        ];
        $releases = $this->catalog(new MockResponse((string) json_encode(['resultCount' => 3, 'results' => $rows])), ['country' => null])->releases('Anyone', 50, 'FR');
        self::assertSame(['Newer', 'Between', 'Older'], array_map(static fn (Release $r) => $r->title, $releases));
        self::assertSame([Release::SINGLE, Release::EP, Release::ALBUM], array_map(static fn (Release $r) => $r->type, $releases));
        self::assertSame([null, 'Shifting Sounds', 'Shaboom'], array_map(static fn (Release $r) => $r->label?->name, $releases));
        self::assertSame('fr', $this->calls[1][1]['country'], 'the country asked for, over the configured one');
    }

    public function testWhatIsNotApplesIsNotRead(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->catalog()->release(Reference::url('https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy'));
    }

    public function testToldToSlowDownOrDownIsUnavailable(): void
    {
        foreach ([403, 429] as $status) {
            try {
                $this->catalog(new MockResponse('', ['http_code' => $status, 'response_headers' => ['Retry-After' => '30']]))->release(Reference::upc(self::UPC));
                self::fail('RateLimitedException expected');
            } catch (RateLimitedException $e) {
                self::assertSame(30, $e->retryAfter);
            }
        }
        foreach ([new MockResponse('', ['http_code' => 503]), new MockResponse('', ['error' => 'Connection timed out']), new MockResponse('<html>Maintenance</html>')] as $response) {
            try {
                $this->catalog($response)->releases('Brieuc Vourch');
                self::fail('UnavailableException expected');
            } catch (UnavailableException $e) {
                self::assertSame('itunes', $e->catalog);
            }
        }

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('[itunes] HTTP 400 Invalid value(s) for key(s): [country]');
        $this->catalog(new MockResponse('{"errorMessage":"Invalid value(s) for key(s): [country]", "queryParameters":{}}', ['http_code' => 400]))->release(Reference::upc(self::UPC));
    }
}
