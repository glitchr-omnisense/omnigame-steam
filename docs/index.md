# omnigame/steam

Steam for [omnisense/omnigame](https://github.com/glitchr-omnisense/omnigame): an app's store
record and the store's search, with no key, and - with a Steam Web API key - a player's library and
whether they own a game. The provider calls:

| Operation | Endpoint | Key |
|---|---|---|
| a record | `GET https://store.steampowered.com/api/appdetails?appids=...&cc=...&l=...` | none |
| search | `GET https://store.steampowered.com/api/storesearch/?term=...&l=...&cc=...` | none |
| library, ownership | `GET https://api.steampowered.com/IPlayerService/GetOwnedGames/v1/?key=...&steamid=...&include_appinfo=1&include_played_free_games=1&format=json` | Web API key |

`appdetails` and `storesearch` are the storefront's own endpoints, not documented by Valve. A
publisher's `ISteamUser/CheckAppOwnership` (a publisher key) is not used. Every request sends
`Accept: application/json` and the configured User-Agent.

## Installation

```sh
composer require omnigame/steam
```

Requires PHP 8.2, `omnisense/omnigame` (1.x) and `symfony/http-client` (6.4, 7 or 8).

## Credentials

- None for the store.
- A **Steam Web API key** (`key`, from steamcommunity.com/dev) for players' libraries and
  ownership. It travels in the query string, as the Web API takes it.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `key` | string | `null` | no | the Web API key |
| `language` | string | `english` | no | Steam's language name, or an ISO code mapped to it: `en` english, `fr` french, `de` german, `es` spanish, `it` italian, `pt` portuguese, `pt-br` brazilian, `ru` russian, `ja` japanese, `ko` koreana, `zh` schinese, `zh-tw` tchinese, `pl` polish, `nl` dutch, `sv` swedish, `tr` turkish, `uk` ukrainian; another value is sent as it is |
| `country` | string | `US` | no | the prices' country (`cc`), upper-cased |
| `user_agent` | string | `omnigame/1.0 (+https://github.com/glitchr-omnisense/omnigame)` | no | |
| `store` | string | `https://store.steampowered.com` | no | |
| `api` | string | `https://api.steampowered.com` | no | |

## Capabilities

| Contract | |
|---|---|
| `SearchInterface` | yes |
| `CatalogueInterface` | by Steam app number (digits only) |
| `LibraryInterface`, `OwnershipInterface` | with `key`; a player is a 64-bit Steam ID (17 digits) |
| prices | yes; keyless catalogue |

`attribution`: `Data from Steam; Valve, Steam and their logos are trademarks of Valve Corporation`.

> [!WARNING]
> Known defect: without `key`, `capabilities()` says no library and no ownership, but the provider
> still implements both: `Registry::library('steam')` returns it and `library()` throws
> `InvalidConfigException` (seen in the harness, 2026-10-27), where the registry is expected to
> refuse it.

## Behaviour

**A record.** `appdetails` for one app; an answer whose `success` is not `true` is `null`.

| Field | Read from |
|---|---|
| `title` | `name` |
| `identifiers` | `steam_appid` (else the number asked) |
| `url` | `https://store.steampowered.com/app/<id>/` |
| `summary` | `short_description`, HTML stripped, entities decoded |
| `released` | `release_date.date` read in the store's languages - `18 Apr, 2011`, `Apr 18, 2011`, `18 avr. 2011`, `18. Apr. 2011`, `18 abr. 2011` (month words of English, French, German, Spanish, Italian, Portuguese); `null` for "Coming soon", a quarter or another script |
| `comingSoon` | `release_date.coming_soon` |
| `developers`, `publishers` | as given |
| `genres`, `tags` | `genres[].description`, `categories[].description` (in the language asked) |
| `platforms` | `platforms` (`windows`, `mac`, `linux` true) |
| `price` | `is_free`: 0 in `price_overview.currency`, else `XXX`; otherwise `price_overview` `final`, `initial` (kept when it differs), `discount_percent` |
| `images` | `header_image` (`Header`), `background_raw` (`Background`), every `screenshots[].path_full` (`Screenshot`) |
| `ratings` | `metacritic.score` out of 100 |

**Search.** Apps only (`type: app`), cut to the limit: number, name, page, platforms, price
(`final`, `initial`, the discount computed), `tiny_image` as a 231 x 87 `Header`, Metacritic score
when above 0.

**Library.** `response.games[]`: the app number, `name` (else the number), `playtime_forever`
(minutes), `rtime_last_played` (a UNIX time; 0 is `null`). A private profile answers no games: `[]`.
`owns()` reads the library and looks for the app number: one call per check.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnigame\Model\GameRef;
use Omnigame\Model\Query;
use Omnigame\Steam\SteamFactory;
use Symfony\Component\HttpClient\HttpClient;

$steam = (new SteamFactory(HttpClient::create()))->create(['key' => getenv('STEAM_WEB_API_KEY'), 'language' => 'fr', 'country' => 'FR']);

$portal = $steam->game(GameRef::steam(620));
printf("%s, %.2f %s\n", $portal->title, $portal->price->amount / 100, $portal->price->currency);
foreach ($steam->search(new Query('portal', limit: 3)) as $game) {
    echo $game->identifiers->get('steam'), ' ', $game->title, "\n";
}
var_dump($steam->owns('76561197960287930', GameRef::steam(620)));
```

The record read from the answer recorded on 2026-10-24 (Portal 2, French, France), in the core's
harness on 2026-10-27 - each field of the `Game`, then the call:

```
Portal 2 | 2011-04-18 | 969 EUR initial - -0%
  ids {"steam":"620"} platforms ["windows","linux"]
  dev ["Valve"] pub ["Valve"] genres ["Action","Aventure"] tags ["Solo","Multijoueur","Coopération"]
  summary L'« initiative de tests perpétuels » a été étendue pour vous permettre
  header https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/620/faffc0f560786e2f05104a8d2fac837c6969bf13/header.jpg?t=1790187113
  background https://store.akamai.steamstatic.com/images/storepagebackground/app/620?t=1790187113
  screenshot https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/620/ss_f3f6787d74739d3b2ec8a484b5c994b3d31ef325.1920x1080.jpg?t=1790187113
  rating metacritic 95/100
  https://store.steampowered.com/app/620/
  > GET https://store.steampowered.com/api/appdetails {"appids":"620","cc":"FR","l":"french"}
```

A live call the same morning (06:17 UTC) could not reach the store from the harness's network:
`UnreachableException: Steam could not be reached for GET /api/appdetails: TLS connect error: ...`.
In Symfony:

```yaml
services:
    Omnigame\Steam\SteamFactory:
        arguments: ['@http_client']
    Omnigame\Registry:
        arguments:
            $factories: ['@Omnigame\Steam\SteamFactory']
            $config:
                steam: { factory: steam, options: { key: '%env(default::STEAM_WEB_API_KEY)%', language: fr, country: FR } }
```

## Errors

- unknown app: `null`; a ref that is not a Steam number, or not digits: `NotSupportedException`;
- a status of 400 or more but 404 (a 429 when the store is asked too fast): `ProviderException`
  (`Steam answered 429 for GET /api/appdetails. Too Many Requests`); a network error:
  `UnreachableException`;
- no `key` for a library, a player that is not 17 digits: `InvalidConfigException`.

> [!WARNING]
> Known defect: a network error on the Web API quotes the URL, the key included (`... for
> "https://api.steampowered.com/IPlayerService/GetOwnedGames/v1/?key=SECRET-KEY&..."`, seen in a
> container with no network, 2026-10-27). Keep these messages out of pages and shared logs.

## Verified, and not

Tested offline (`Tests/SteamProviderTest.php`, in the core's harness, PHP 8.4): Portal 2's record
in French with its French price, an unknown app, a search - answers recorded on 2026-10-24 -, the
library and ownership on an answer shaped after the Web API's documentation. `Tests/BareTest.php`
loads every class with the package's requirements alone.

Verified live (`Tests/LiveTest.php`, no key, 2026-10-24): Portal 2's record in English with its US
price, a search. On 2026-10-27 the store could not be reached from the harness (above). Not
verified: the library and ownership (no Web API key was used).

## Responsibility

An independent, unofficial client, affiliated with Valve in no way. Whoever runs it complies with
the [Steam Web API terms](https://steamcommunity.com/dev/apiterms) (100,000 calls a day; Valve's
name and logos only as documented, no implied endorsement, links without "nofollow"; a player's
data only as that player asks, a privacy policy) and keeps a gentle pace on the storefront's
endpoints: see the README.
