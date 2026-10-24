<?php

namespace Omnigame\Steam;

use Omnigame\Config;
use Omnigame\Http\JsonClient;
use Omnigame\ProviderFactory;
use Omnigame\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "steam": options key (a Steam Web API key: only for a player's library and
 * ownership; the store needs none), language ("english", or "fr"),
 * country ("US": the prices'), user_agent, store, api.
 */
final class SteamFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnigame.factory_name' => 'steam',
            'omnigame.factory_title' => 'Steam',
            'omnigame.required_options' => [],
            'key' => null,
            'language' => 'english',
            'country' => 'US',
            'user_agent' => 'omnigame/1.0 (+https://github.com/glitchr-studio/omnigame)',
            'store' => 'https://store.steampowered.com',
            'api' => 'https://api.steampowered.com',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new SteamProvider(
            new JsonClient($this->http ?? HttpClient::create(['timeout' => 20]), 'Steam', ['Accept' => 'application/json', 'User-Agent' => (string) $c->string('user_agent')]),
            $c->string('key'),
            (string) $c->string('language'),
            (string) $c->string('country'),
            rtrim((string) $c->string('store'), '/'),
            rtrim((string) $c->string('api'), '/'),
        );
    }
}
