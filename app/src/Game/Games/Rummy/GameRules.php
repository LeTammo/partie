<?php

declare(strict_types=1);

namespace App\Game\Games\Rummy;

use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Rank;

final class GameRules
{
    public const int INITIAL_MELD_POINTS = 40;

    /**
     * @param list<PlayingCard> $cards
     *
     * @return array{type: string, points: int}|null
     */
    public function validateMeld(array $cards): ?array
    {
        $n = \count($cards);
        if ($n < 3) {
            return null;
        }

        $nonJokers = array_values(array_filter($cards, static fn (PlayingCard $c): bool => !$c->joker));
        if ([] === $nonJokers) {
            return null;
        }

        if (null !== ($points = $this->setPoints($nonJokers, $n))) {
            return ['type' => 'set', 'points' => $points];
        }
        if (null !== ($points = $this->runPoints($nonJokers, $n))) {
            return ['type' => 'run', 'points' => $points];
        }

        return null;
    }

    /**
     * @param list<PlayingCard> $nonJokers
     */
    private function setPoints(array $nonJokers, int $total): ?int
    {
        if ($total > 4) {
            return null;
        }

        $rank = $nonJokers[0]->rank;
        $suits = [];
        foreach ($nonJokers as $card) {
            if ($card->rank !== $rank || isset($suits[$card->suit->value])) {
                return null;
            }
            $suits[$card->suit->value] = true;
        }

        return $this->rankPoints($rank) * $total;
    }

    /**
     * @param list<PlayingCard> $nonJokers
     */
    private function runPoints(array $nonJokers, int $total): ?int
    {
        $suit = $nonJokers[0]->suit;
        if (array_any($nonJokers, fn($card) => $card->suit !== $suit)) {
            return null;
        }

        foreach ([true, false] as $aceHigh) {
            $values = [];
            foreach ($nonJokers as $card) {
                $value = Rank::Ace === $card->rank && !$aceHigh ? 1 : $card->rank->value;
                if (\in_array($value, $values, true)) {
                    continue 2;
                }
                $values[] = $value;
            }

            $min = min($values);
            $max = max($values);
            if ($max - $min + 1 > $total) {
                continue;
            }

            $lowBound = $aceHigh ? Rank::Two->value : 1;
            $highBound = $aceHigh ? Rank::Ace->value : Rank::King->value;
            $start = min($min, $highBound - $total + 1);
            if ($start < max($lowBound, $max - $total + 1)) {
                continue;
            }

            $points = 0;
            for ($v = $start; $v < $start + $total; ++$v) {
                $points += $this->valuePoints($v);
            }

            return $points;
        }

        return null;
    }

    public function rankPoints(Rank $rank): int
    {
        return match (true) {
            Rank::Ace === $rank => 11,
            $rank->value >= Rank::Ten->value => 10,
            default => $rank->value,
        };
    }

    private function valuePoints(int $value): int
    {
        return match (true) {
            1 === $value => 1,
            14 === $value => 11,
            $value >= 10 => 10,
            default => $value,
        };
    }
}
