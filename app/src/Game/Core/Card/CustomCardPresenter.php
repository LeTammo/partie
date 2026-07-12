<?php

declare(strict_types=1);

namespace App\Game\Core\Card;

final class CustomCardPresenter
{
    /**
     * @return array{value: string, color: string, identity: string}
     */
    public static function view(CustomCard $card): array
    {
        return [
            'value' => $card->value,
            'color' => $card->color,
            'identity' => $card->identity(),
        ];
    }

    /**
     * @param list<CustomCard> $cards
     *
     * @return list<array{value: string, color: string, identity: string}>
     */
    public static function views(array $cards): array
    {
        return array_map(self::view(...), $cards);
    }
}
