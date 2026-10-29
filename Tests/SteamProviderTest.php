<?php

namespace Omnigame\Steam\Tests;

use Omnigame\Exception\InvalidConfigException;
use Omnigame\Exception\NotSupportedException;
use Omnigame\Model\Game;
use Omnigame\Model\GameRef;
use Omnigame\Model\ImageKind;
use Omnigame\Model\OwnedGame;
use Omnigame\Model\Platform;
use Omnigame\Model\Query;
use Omnigame\Steam\SteamFactory;
use Omnigame\Steam\SteamProvider;
use Omnigame\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The store's answers recorded on 2026-10-24 (appdetails trimmed to the
 * fields read); GetOwnedGames' answer shaped after the Web API's
 * documentation, written by hand (no key was used).
 */
final class SteamProviderTest extends TestCase
{
    public function testAnAppsStoreRecordInFrenchWithItsFrenchPrice(): void
    {
        $http = self::http();
        $game = self::provider($http)->game(GameRef::steam(620), 'fr', 'fr');

        self::assertNotNull($game);
        self::assertSame(['Portal 2', '2011-04-18', false, ['Valve'], ['Action', 'Aventure']], [$game->title, $game->released?->format('Y-m-d'), $game->comingSoon, $game->developers, $game->genres]);
        self::assertStringContainsString('initiative de tests perpétuels', (string) $game->summary);
        self::assertSame([Platform::Windows, Platform::Linux], $game->platforms);
        self::assertSame(['amount' => 969, 'currency' => 'EUR', 'initial' => null, 'discount' => 0], $game->price?->toArray());
        self::assertSame('https://store.steampowered.com/app/620/', $game->url);
        self::assertNotNull($game->image(ImageKind::Header));
        self::assertCount(2, array_filter($game->images, static fn ($i) => ImageKind::Screenshot === $i->kind));
        self::assertSame(['metacritic', 95.0], [$game->ratings[0]->source ?? null, $game->ratings[0]->value ?? null]);
        $query = $http->requests[0]['options']['query'] ?? [];
        self::assertIsArray($query);
        self::assertSame(['appids' => '620', 'cc' => 'FR', 'l' => 'french'], $query);
    }

    public function testAnUnknownAppIsNull(): void
    {
        self::assertNull(self::provider(self::http())->game(GameRef::steam(1)));
    }

    public function testASearchOfTheStore(): void
    {
        $games = self::provider(self::http(), ['language' => 'fr', 'country' => 'FR'])->search(new Query('portal', limit: 2));

        self::assertSame([['Portal 2', '620', 969], ['Portal', '400', 969]], array_map(static fn (Game $g) => [$g->title, $g->identifiers->get('steam'), $g->price?->amount], $games));
    }

    public function testAPlayersLibraryAndOwnershipWithAKey(): void
    {
        $http = self::http();
        $provider = self::provider($http, ['key' => 'not-a-real-steam-web-api-key']);

        self::assertTrue($provider->capabilities()->library);
        self::assertSame([['620', 'Portal 2', 1250, '2026-01-02']], array_map(static fn (OwnedGame $g) => [$g->identifiers->get('steam'), $g->title, $g->playtime, $g->lastPlayed?->format('Y-m-d')], $provider->library('76561197960287930')));
        self::assertTrue($provider->owns('76561197960287930', GameRef::steam(620)));
        self::assertFalse($provider->owns('76561197960287930', GameRef::steam(400)));
        $query = $http->requests[0]['options']['query'] ?? [];
        self::assertIsArray($query);
        self::assertSame(['key' => 'not-a-real-steam-web-api-key', 'steamid' => '76561197960287930', 'include_appinfo' => 1, 'include_played_free_games' => 1, 'format' => 'json'], $query);
    }

    public function testALibraryNeedsAKeyAndASteamId(): void
    {
        $provider = self::provider(self::http());
        self::assertFalse($provider->capabilities()->library);
        try {
            $provider->library('76561197960287930');
            self::fail('No key.');
        } catch (InvalidConfigException $e) {
            self::assertStringContainsString('"key" option', $e->getMessage());
        }
        $this->expectException(InvalidConfigException::class);
        self::provider(self::http(), ['key' => 'x'])->library('gaben');
    }

    /** The registry offers a library and ownership only when the provider can give them: Steam without a key cannot. */
    public function testTheRegistryOffersNoLibraryWithoutAKey(): void
    {
        $registry = new \Omnigame\Registry([new SteamFactory(self::http())], ['steam' => ['factory' => 'steam'], 'keyed' => ['factory' => 'steam', 'options' => ['key' => 'x']]]);

        self::assertInstanceOf(\Omnigame\LibraryInterface::class, $registry->library('keyed'));
        self::assertInstanceOf(\Omnigame\OwnershipInterface::class, $registry->ownership('keyed'));
        foreach (['library' => fn () => $registry->library('steam'), 'ownership' => fn () => $registry->ownership('steam')] as $what => $ask) {
            try {
                $ask();
                self::fail($what.' offered without a key');
            } catch (\Omnigame\Exception\NotSupportedException $e) {
                self::assertStringContainsString('"steam"', $e->getMessage());
            }
        }
    }

    public function testOnlyAppIdsAreKnown(): void
    {
        $this->expectException(NotSupportedException::class);
        self::provider(self::http())->game(GameRef::gog(1207658924));
    }

    private static function http(): RecordedHttpClient
    {
        $f = '@'.__DIR__.'/Fixtures/';

        return RecordedHttpClient::routes([
            '~/api/appdetails\?appids=620&~' => [200, $f.'appdetails-620-fr.json'],
            '~/api/appdetails\?appids=1&~' => [200, $f.'appdetails-unknown.json'],
            '~/api/storesearch/\?term=portal~' => [200, $f.'storesearch-portal.json'],
            '~/IPlayerService/GetOwnedGames/v1/~' => [200, '{"response": {"game_count": 1, "games": [{"appid": 620, "name": "Portal 2", "playtime_forever": 1250, "img_icon_url": "0000", "rtime_last_played": 1767355200}]}}'],
        ]);
    }

    /** @param array<string, mixed> $options */
    private static function provider(RecordedHttpClient $http, array $options = []): SteamProvider
    {
        $provider = (new SteamFactory($http))->create($options);
        self::assertInstanceOf(SteamProvider::class, $provider);

        return $provider;
    }
}
