<?php

declare(strict_types=1);

namespace App\Game\Core\Card;

/**
 * Maps playing cards to render-ready arrays for the `templates/components/card.html.twig` component.
 */
final class CardPresenter
{
    /**
     * @return array{rank: string|null, suit: string|null, red: bool, joker: bool, identity: string}
     */
    public static function view(PlayingCard $card): array
    {
        return [
            'rank' => $card->joker ? null : $card->rank->labelKey(),
            'suit' => $card->joker ? null : $card->suit->symbol(),
            'red' => !$card->joker && $card->suit->isRed(),
            'joker' => $card->joker,
            // a stable, card-game-agnostic identity string (e.g. for Twig `key`/`flip` attributes
            // on components/card.html.twig), so games don't each recompute rank+suit by hand
            'identity' => $card->joker ? 'joker' : $card->rank->labelKey().'-'.$card->suit->symbol(),
        ];
    }

    /**
     * @param list<PlayingCard> $cards
     *
     * @return list<array{rank: string|null, suit: string|null, red: bool, joker: bool, identity: string}>
     */
    public static function views(array $cards): array
    {
        return array_map(self::view(...), $cards);
    }
}
