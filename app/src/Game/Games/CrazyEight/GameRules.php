<?php

declare(strict_types=1);

namespace App\Game\Games\CrazyEight;

use App\Game\Core\Card\CustomCard;

final class GameRules
{
    public const string SKIP = '⊘';
    public const string REVERSE = '⇄';
    public const string DRAW_TWO = '+2';
    public const string WILD = '★';
    public const string WILD_FOUR = '+4';
    public const string WILD_COLOR = 'wild';

    public function isWild(CustomCard $card): bool
    {
        return self::WILD_COLOR === $card->color;
    }

    public function isAction(CustomCard $card): bool
    {
        return \in_array($card->value, [self::SKIP, self::REVERSE, self::DRAW_TWO], true);
    }

    public function playable(
        CustomCard $card,
        CustomCard $top,
        ?string $wishedColor,
        int $pendingDraw,
        ?string $pendingDrawValue,
        bool $penaltyLocked,
        bool $stackDraw2,
    ): bool {
        if ($pendingDraw > 0) {
            return !$penaltyLocked && $stackDraw2 && $card->value === $pendingDrawValue;
        }

        if ($this->isWild($card)) {
            return true;
        }

        if (null !== $wishedColor) {
            return $card->color === $wishedColor;
        }

        return $card->color === $top->color || $card->value === $top->value;
    }
}
