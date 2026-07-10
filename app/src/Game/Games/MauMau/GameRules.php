<?php

declare(strict_types=1);

namespace App\Game\Games\MauMau;

use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Rank;

final class GameRules
{
    public const int DRAW_PENALTY = 2;

    public function playable(
        PlayingCard $card,
        PlayingCard $top,
        ?string $wishedSuit,
        int $pendingDraw,
        bool $penaltyLocked,
        int $pendingSkip,
        Options $options,
    ): bool {
        if ($pendingSkip > 0) {
            return $options->stackSkip && $card->rank === $options->skipRank;
        }

        if ($pendingDraw > 0) {
            return !$penaltyLocked && $options->stackDraw && $card->rank === $options->drawRank;
        }

        if (Rank::Jack === $card->rank) {
            return $options->allowRewish || Rank::Jack !== $top->rank;
        }

        if (null !== $wishedSuit) {
            return $card->suit->value === $wishedSuit;
        }

        return $card->suit === $top->suit || $card->rank === $top->rank;
    }
}
