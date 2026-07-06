<?php

declare(strict_types=1);

namespace App\Game\Games\MauMau;

use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Rank;

final class GameRules
{
    public const int DRAW_PENALTY = 2;

    public function playable(PlayingCard $card, PlayingCard $top, ?string $wishedSuit, int $pendingDraw): bool
    {
        if ($pendingDraw > 0) {
            return Rank::Seven === $card->rank;
        }

        if (Rank::Jack === $card->rank) {
            return Rank::Jack !== $top->rank;
        }

        if (null !== $wishedSuit) {
            return $card->suit->value === $wishedSuit;
        }

        return $card->suit === $top->suit || $card->rank === $top->rank;
    }
}
