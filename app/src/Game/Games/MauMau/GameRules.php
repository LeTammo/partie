<?php

declare(strict_types=1);

namespace App\Game\Games\MauMau;

use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Rank;

final class GameRules
{
    public const int DRAW_PENALTY = 2;

    /**
     * @param bool $penaltyLocked once the player facing a pending draw has drawn any of it,
     *                            they are committed to drawing the rest – no more countering with a 7
     */
    public function playable(PlayingCard $card, PlayingCard $top, ?string $wishedSuit, int $pendingDraw, bool $penaltyLocked = false): bool
    {
        if ($pendingDraw > 0) {
            return !$penaltyLocked && Rank::Seven === $card->rank;
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
