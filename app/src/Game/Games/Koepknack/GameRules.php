<?php

declare(strict_types=1);

namespace App\Game\Games\Koepknack;

use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Rank;

final class GameRules
{
    public const float FIRE = 33.0;
    public const float KNACK = 31.0;

    public function points(PlayingCard $card): int
    {
        return match (true) {
            Rank::Ace === $card->rank => 11,
            $card->rank->value >= Rank::Ten->value => 10,
            default => $card->rank->value,
        };
    }

    /**
     * @param list<PlayingCard> $hand
     */
    public function value(array $hand): float
    {
        if ($this->isFire($hand)) {
            return self::FIRE;
        }

        if (3 === \count($hand)
            && $hand[0]->rank === $hand[1]->rank
            && $hand[1]->rank === $hand[2]->rank) {
            return 30.5;
        }

        $suitSums = [];
        foreach ($hand as $card) {
            $suitSums[$card->suit->value] = ($suitSums[$card->suit->value] ?? 0) + $this->points($card);
        }

        return (float) max($suitSums);
    }

    /**
     * @param list<PlayingCard> $hand
     */
    public function isFire(array $hand): bool
    {
        if (3 !== \count($hand)) {
            return false;
        }

        return array_all($hand, fn($card) => Rank::Ace === $card->rank);
    }

    public function format(float $value): string
    {
        return abs($value - floor($value)) > 0.01
            ? number_format($value, 1)
            : (string) (int) $value;
    }
}
