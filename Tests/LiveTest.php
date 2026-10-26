<?php

namespace Omnigame\Steam\Tests;

use Omnigame\Model\GameRef;
use Omnigame\Model\Query;
use Omnigame\Steam\SteamFactory;
use Omnigame\Steam\SteamProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Steam's store itself, gently (two requests): an app's record and a
 * search. `docker compose run --rm omnigame live`; never in the default run.
 */
#[Group('live')]
final class LiveTest extends TestCase
{
    public function testTheStore(): void
    {
        $provider = (new SteamFactory(HttpClient::create(['timeout' => 30])))->create(['user_agent' => 'omnigame-tests/1.0 (+https://github.com/glitchr-omnisense/omnigame)']);
        self::assertInstanceOf(SteamProvider::class, $provider);

        $game = $provider->game(GameRef::steam(620), 'en', 'US');
        self::assertNotNull($game);
        self::assertSame(['Portal 2', '2011-04-18', ['Valve']], [$game->title, $game->released?->format('Y-m-d'), $game->developers]);
        self::assertSame('USD', $game->price?->currency);

        self::assertContains('620', array_map(static fn ($g) => $g->identifiers->get('steam'), $provider->search(new Query('portal 2'))));
    }
}
