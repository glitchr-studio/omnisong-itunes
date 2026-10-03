# omnisong/itunes

The iTunes Search API for [glitchr/omnisong](https://github.com/glitchr-studio/omnisong): a
release with its tracks, their 30-second previews and its artwork, and an artist's albums - no
key, through the application's HTTP client.

```yaml
omnisong:
    catalogs:
        itunes:
            factory: itunes
            options:
                country: 'de'                          # the store asked (default us)
                base_uri: 'https://itunes.apple.com/'
```

`release()` reads a UPC (`lookup?upc=`), an ISRC (`lookup?isrc=`, then the track's album), an
Apple Music / iTunes URL (its album id) or the id itself (`Reference::id(Platform::ITUNES, ...)`),
and answers with the title (the store's " - Single" / " - EP" read as the type), the artist, the
label named by the `℗` line, the release date, the cover at 1200 px, the genre, and each track:
title, number, disc, duration, `previewUrl`, its artist, its Apple Music URL. `links()` is the
release's Apple Music link and the iTunes Store's; `releases($artist)` is
`search?entity=album&attribute=artistTerm`, newest first, without the tracks.

What the API does not give: ISRCs and composers (both stay `null`), and any link outside Apple -
those are `omnisong/odesli`'s, which the aggregator asks by the id found here.

Unknown is `null` / an empty list; 403 and 429 (about 20 calls a minute are allowed) are a
`RateLimitedException`; 5xx and a transport error an `UnavailableException`; any other 4xx a
`ProviderException`. The previews are Apple's, for promotional use next to a link to the store:
keep the Apple Music link beside the player.

License: LGPL-3.0-or-later.
