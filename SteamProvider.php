<?php

namespace Omnigame\Steam;

use Omnigame\Capabilities;
use Omnigame\CatalogueInterface;
use Omnigame\Exception\InvalidConfigException;
use Omnigame\Exception\NotSupportedException;
use Omnigame\Http\Data;
use Omnigame\Http\JsonClient;
use Omnigame\LibraryInterface;
use Omnigame\Model\Game;
use Omnigame\Model\GameRef;
use Omnigame\Model\Identifiers;
use Omnigame\Model\Image;
use Omnigame\Model\ImageKind;
use Omnigame\Model\OwnedGame;
use Omnigame\Model\Platform;
use Omnigame\Model\Price;
use Omnigame\Model\Query;
use Omnigame\Model\Rating;
use Omnigame\Model\Scheme;
use Omnigame\OwnershipInterface;
use Omnigame\ProviderInterface;
use Omnigame\SearchInterface;

/**
 * Steam: the store's record of an app and its search (the storefront's own
 * endpoints, no key: appdetails, storesearch), and - with a Web API key - a
 * player's library (IPlayerService/GetOwnedGames) and whether they own a
 * game. A player is a 64-bit Steam ID; a private profile reads as an empty
 * library.
 */
final class SteamProvider implements ProviderInterface, SearchInterface, CatalogueInterface, LibraryInterface, OwnershipInterface
{
    /** ISO 639-1 to the store's language names (its "l" parameter). */
    private const LANGUAGES = ['en' => 'english', 'fr' => 'french', 'de' => 'german', 'es' => 'spanish', 'it' => 'italian', 'pt' => 'portuguese', 'pt-br' => 'brazilian', 'ru' => 'russian', 'ja' => 'japanese', 'ko' => 'koreana', 'zh' => 'schinese', 'zh-tw' => 'tchinese', 'pl' => 'polish', 'nl' => 'dutch', 'sv' => 'swedish', 'tr' => 'turkish', 'uk' => 'ukrainian'];

    public function __construct(
        private readonly JsonClient $json,
        private readonly ?string $key = null,
        private readonly string $language = 'english',
        private readonly string $country = 'US',
        private readonly string $store = 'https://store.steampowered.com',
        private readonly string $api = 'https://api.steampowered.com',
    ) {
    }

    public function getName(): string
    {
        return 'steam';
    }

    public function getTitle(): string
    {
        return 'Steam';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            search: true,
            catalogue: true,
            library: null !== $this->key,
            ownership: null !== $this->key,
            lookupBy: [Scheme::Steam],
            prices: true,
            player: 'a 64-bit Steam ID ("76561197960287930")',
            attribution: 'Data from Steam; Valve, Steam and their logos are trademarks of Valve Corporation',
            terms: ['https://steamcommunity.com/dev/apiterms', 'https://store.steampowered.com/subscriber_agreement/'],
            keyless: true,
        );
    }

    public function search(Query $query): array
    {
        $answer = $this->json->get($this->store.'/api/storesearch/', ['term' => $query->text, 'l' => $this->steamLanguage($query->language), 'cc' => strtoupper($query->country ?? $this->country)]);
        $games = [];
        foreach ($answer?->list('items') ?? [] as $item) {
            $id = $item->string('id');
            if (null === $id || 'app' !== $item->string('type')) {
                continue;
            }
            $image = $item->string('tiny_image');
            $metascore = $item->int('metascore');
            $games[] = new Game(
                title: (string) $item->string('name'),
                identifiers: Identifiers::of(['steam' => $id]),
                source: 'steam',
                url: $this->store.'/app/'.$id.'/',
                platforms: self::platforms($item->data('platforms')),
                price: self::price($item->data('price'), 'final', 'initial', null),
                images: null === $image ? [] : [new Image(ImageKind::Header, $image, 231, 87)],
                ratings: null !== $metascore && $metascore > 0 ? [new Rating('metacritic', (float) $metascore)] : [],
            );
        }

        return \array_slice($games, 0, $query->limit);
    }

    public function game(GameRef $ref, ?string $language = null, ?string $country = null): ?Game
    {
        if (!$this->capabilities()->resolves($ref) || !ctype_digit($ref->id)) {
            throw NotSupportedException::ref($this->getTitle(), $ref);
        }
        $answer = $this->json->get($this->store.'/api/appdetails', ['appids' => $ref->id, 'cc' => strtoupper($country ?? $this->country), 'l' => $this->steamLanguage($language)]);
        $entry = $answer?->data($ref->id);
        if (null === $entry || true !== $entry->bool('success')) {
            return null;
        }
        $data = $entry->data('data');
        $images = [];
        foreach (['header_image' => ImageKind::Header, 'background_raw' => ImageKind::Background] as $field => $kind) {
            if (null !== $url = $data->string($field)) {
                $images[] = new Image($kind, $url);
            }
        }
        foreach ($data->list('screenshots') as $screenshot) {
            if (null !== $url = $screenshot->string('path_full')) {
                $images[] = new Image(ImageKind::Screenshot, $url);
            }
        }
        $metacritic = $data->int('metacritic.score');
        $id = $data->string('steam_appid') ?? $ref->id;

        return new Game(
            title: (string) $data->string('name'),
            identifiers: Identifiers::of(['steam' => $id]),
            source: 'steam',
            url: $this->store.'/app/'.$id.'/',
            summary: null === ($summary = $data->string('short_description')) ? null : html_entity_decode(strip_tags($summary), \ENT_QUOTES | \ENT_HTML5),
            released: self::date($data->string('release_date.date')),
            comingSoon: true === $data->bool('release_date.coming_soon'),
            developers: $data->strings('developers'),
            publishers: $data->strings('publishers'),
            genres: $data->strings('genres', 'description'),
            tags: $data->strings('categories', 'description'),
            platforms: self::platforms($data->data('platforms')),
            price: true === $data->bool('is_free') ? new Price(0, $data->string('price_overview.currency') ?? 'XXX') : self::price($data->data('price_overview'), 'final', 'initial', 'discount_percent'),
            images: $images,
            ratings: null !== $metacritic ? [new Rating('metacritic', (float) $metacritic)] : [],
        );
    }

    public function library(string $player): array
    {
        $answer = $this->json->get($this->api.'/IPlayerService/GetOwnedGames/v1/', ['key' => $this->key ?? throw new InvalidConfigException('A player\'s Steam library needs the "key" option (a Steam Web API key).'), 'steamid' => self::player($player), 'include_appinfo' => 1, 'include_played_free_games' => 1, 'format' => 'json']);
        $games = [];
        foreach ($answer?->list('response.games') ?? [] as $game) {
            $last = $game->int('rtime_last_played');
            $games[] = new OwnedGame(Identifiers::of(['steam' => $game->string('appid')]), (string) ($game->string('name') ?? $game->string('appid')), $game->int('playtime_forever'), null !== $last && $last > 0 ? (new \DateTimeImmutable('@'.$last)) : null);
        }

        return $games;
    }

    public function owns(string $player, GameRef $game): bool
    {
        if (Scheme::Steam !== $game->scheme) {
            throw NotSupportedException::ref($this->getTitle(), $game);
        }
        foreach ($this->library($player) as $owned) {
            if ($game->id === $owned->identifiers->get(Scheme::Steam)) {
                return true;
            }
        }

        return false;
    }

    private function steamLanguage(?string $language): string
    {
        $language = strtolower(str_replace('_', '-', $language ?? $this->language));

        return self::LANGUAGES[$language] ?? self::LANGUAGES[explode('-', $language)[0]] ?? $language;
    }

    private static function player(string $player): string
    {
        return preg_match('~^\d{17}$~', $player) ? $player : throw new InvalidConfigException(\sprintf('"%s" is not a 64-bit Steam ID.', $player));
    }

    /** @return list<Platform> */
    private static function platforms(Data $platforms): array
    {
        return Platform::fromLabels(array_map('strval', array_keys(array_filter($platforms->toArray(), static fn (mixed $on) => true === $on))));
    }

    private static function price(Data $price, string $final, string $initial, ?string $discount): ?Price
    {
        $amount = $price->int($final);
        $currency = $price->string('currency');
        if (null === $amount || null === $currency) {
            return null;
        }
        $before = $price->int($initial);

        return new Price($amount, $currency, null !== $before && $before !== $amount ? $before : null, null === $discount ? (null !== $before && $before > 0 ? (int) round(100 * ($before - $amount) / $before) : 0) : (int) $price->int($discount));
    }

    /** The month words of the store's languages, by their first letters (accents and dots dropped). */
    private const MONTHS = ['juil' => 7, 'juin' => 6, 'jan' => 1, 'ene' => 1, 'gen' => 1, 'feb' => 2, 'fev' => 2, 'mar' => 3, 'apr' => 4, 'avr' => 4, 'abr' => 4, 'may' => 5, 'mai' => 5, 'mag' => 5, 'jun' => 6, 'giu' => 6, 'jul' => 7, 'lug' => 7, 'aug' => 8, 'aou' => 8, 'ago' => 8, 'sep' => 9, 'set' => 9, 'oct' => 10, 'okt' => 10, 'ott' => 10, 'out' => 10, 'nov' => 11, 'dec' => 12, 'dez' => 12, 'dic' => 12];

    /**
     * The store's date in the language asked: "18 Apr, 2011", "Apr 18, 2011",
     * "18 avr. 2011", "18. Apr. 2011", "18 abr. 2011"; null for "Coming soon"
     * or a quarter.
     */
    private static function date(?string $date): ?\DateTimeImmutable
    {
        $words = preg_split('~[^\pL\d]+~u', strtr(mb_strtolower((string) $date), ['é' => 'e', 'è' => 'e', 'û' => 'u', 'ä' => 'a', 'ô' => 'o']), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        [$day, $month, $year] = [null, null, null];
        foreach ($words as $word) {
            if (preg_match('~^\d{4}$~', $word)) {
                $year = (int) $word;
            } elseif (preg_match('~^\d{1,2}$~', $word)) {
                $day ??= (int) $word;
            } else {
                foreach (self::MONTHS as $prefix => $number) {
                    if (null === $month && str_starts_with($word, (string) $prefix)) {
                        $month = $number;
                    }
                }
            }
        }

        return null !== $day && null !== $month && null !== $year && checkdate($month, $day, $year) ? (\DateTimeImmutable::createFromFormat('!Y-n-j', $year.'-'.$month.'-'.$day, new \DateTimeZone('UTC')) ?: null) : null;
    }
}
