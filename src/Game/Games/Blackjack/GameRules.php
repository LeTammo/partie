<?php

declare(strict_types=1);

namespace App\Game\Games\Blackjack;

use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Rank;

final class GameRules
{
    public const array BET_OPTIONS = [10, 20, 50, 100];
    public const int MIN_BET = 10;
    public const int DEALER_STANDS_AT = 17;

    /**
     * @param list<PlayingCard> $hand
     */
    public function value(array $hand): int
    {
        $total = 0;
        $aces = 0;
        foreach ($hand as $card) {
            if (Rank::Ace === $card->rank) {
                ++$aces;
                $total += 11;
            } elseif ($card->rank->value >= Rank::Ten->value) {
                $total += 10;
            } else {
                $total += $card->rank->value;
            }
        }
        while ($total > 21 && $aces > 0) {
            $total -= 10;
            --$aces;
        }

        return $total;
    }

    /**
     * @param list<PlayingCard> $hand
     */
    public function isBlackjack(array $hand): bool
    {
        return 2 === \count($hand) && 21 === $this->value($hand);
    }

    /**
     * @param list<PlayingCard> $hand
     */
    public function isBust(array $hand): bool
    {
        return $this->value($hand) > 21;
    }
}
