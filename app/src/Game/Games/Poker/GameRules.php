<?php

declare(strict_types=1);

namespace App\Game\Games\Poker;

final class GameRules
{
    /**
     * Layer side pots from each player's total contribution this hand.
     *
     * @param array<string, int>  $contributed playerId => total chips put in this hand
     * @param array<string, bool> $folded      playerId => folded this hand
     *
     * @return list<array{amount: int, eligible: list<string>}>
     */
    public function sidePots(array $contributed, array $folded): array
    {
        $levels = array_values(array_unique(array_filter(array_values($contributed), static fn (int $c): bool => $c > 0)));
        sort($levels);

        $pots = [];
        $previous = 0;
        foreach ($levels as $level) {
            $slice = $level - $previous;
            $contributors = array_keys(array_filter($contributed, static fn (int $c): bool => $c >= $level));
            $amount = $slice * \count($contributors);
            $eligible = array_values(array_filter($contributors, static fn (string $id) => !($folded[$id] ?? false)));

            if ($amount > 0 && [] !== $eligible) {
                $pots[] = ['amount' => $amount, 'eligible' => $eligible];
            }
            $previous = $level;
        }

        return $pots;
    }
}
