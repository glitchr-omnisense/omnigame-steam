# omnigame/steam

[omnisense/omnigame](https://github.com/glitchr-omnisense/omnigame)'s Steam, under any PHP host:

- **an app's store record** (`store.steampowered.com/api/appdetails`, no key): name, short
  description, release date (read in the store's languages: "18 Apr, 2011", "18 avr. 2011"),
  developers, publishers, genres, categories, platforms, price in the country asked, header,
  background and screenshots, Metacritic score;
- **the store's search** (`/api/storesearch`, no key);
- **a player's library** and **ownership** with a Steam Web API key
  (`IPlayerService/GetOwnedGames`); a player is a 64-bit Steam ID, and a private profile reads as
  an empty library. A publisher's `ISteamUser/CheckAppOwnership` (publisher key) is not used.

```php
$steam = (new SteamFactory($httpClient))->create(['key' => getenv('STEAM_WEB_API_KEY'), 'language' => 'fr', 'country' => 'FR']);
$portal = $steam->game(GameRef::steam(620));
```

Options: `key`, `language` ("english", or an ISO code: "fr" asks "french"), `country` ("US"),
`user_agent`, `store`, `api`.

## Valve's terms, as read on 2026-10-24

From the [Steam Web API terms](https://steamcommunity.com/dev/apiterms): "one hundred thousand
(100,000) calls to the Steam Web API per day"; Valve's name and logos only as the documentation
describes, no implied endorsement, links to Valve without "nofollow"; Steam data about a player
retrieved only as that player requests; a privacy policy saying what is stored. The store's
`appdetails` and `storesearch` are the storefront's own endpoints, not documented by Valve: keep
to a gentle pace and keep what you read.

## Verified, and not

- Verified (the core's harness, PHP 8.4): Portal 2's record in French with its French price, an
  unknown app, a search - answers recorded on 2026-10-24 -; the library and ownership on an answer
  shaped after the Web API's documentation (no key); `BareTest`; PHPStan max.
- Verified live (no key, 2026-10-24): Portal 2's record in English with its US price, a search.
- Not verified live: the library and ownership (no Web API key was used).

## Responsibility

An independent, unofficial client, affiliated with, endorsed by or sponsored by none of the services
it reaches. The MIT licence covers this code only and grants no right to the service or its data:
whoever runs it complies with the terms above and obtains what they require. The authors publish
it free of charge, make no commercial use of it and accept no liability for its use.

## Licence

MIT.
