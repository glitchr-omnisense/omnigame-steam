<?php

namespace Omnigame\Steam\Tests;

use Omnigame\Steam\SteamProvider;
use Omnigame\Testing\BareTestCase;

/** omnigame/steam's classes load with omnisense/omnigame and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return SteamProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
