<?php

declare(strict_types=1);

namespace App\Game\Games\Ludo;

final class GameRules
{
    public const int PAWNS_PER_PLAYER = 4;
    public const int RING_LENGTH = 40;
    public const int GOAL_STEPS = 4;
    public const int FINISH_PROGRESS = self::RING_LENGTH + self::GOAL_STEPS - 1;

    public function startIndex(int $seat): int
    {
        return (10 * $seat) % self::RING_LENGTH;
    }

    public function ringIndexFor(int $seat, int $progress): ?int
    {
        if ($progress < 0 || $progress > self::RING_LENGTH - 1) {
            return null;
        }

        return ($this->startIndex($seat) + $progress) % self::RING_LENGTH;
    }

    /**
     * @param array<string, list<int>> $pawns
     * @param array<string, int>       $seats
     *
     * @return array{playerId: string, pawnIndex: int}|null
     */
    public function pawnAtRingIndex(
        array $pawns,
        array $seats,
        int $ringIndex,
        ?string $excludePlayerId = null,
        ?int $excludePawnIndex = null
    ): ?array
    {
        foreach ($pawns as $playerId => $progresses) {
            $seat = $seats[$playerId];
            foreach ($progresses as $pawnIndex => $progress) {
                if ($playerId === $excludePlayerId && $pawnIndex === $excludePawnIndex) {
                    continue;
                }
                if ($this->ringIndexFor($seat, $progress) === $ringIndex) {
                    return ['playerId' => $playerId, 'pawnIndex' => $pawnIndex];
                }
            }
        }

        return null;
    }

    /**
     * @param list<int> $ownPawns
     */
    private function goalSlotTaken(array $ownPawns, int $progress, int $excludePawnIndex): bool
    {
        return array_any($ownPawns, fn($p, $pawnIndex) => $pawnIndex !== $excludePawnIndex && $p === $progress);

    }

    /**
     * Whether any own pawn sits in a goal-stretch slot strictly between $fromProgress and $target
     * (inclusive of $target) - used when overtaking within the goal stretch is disallowed.
     *
     * @param list<int> $ownPawns
     */
    private function goalStretchBlocked(array $ownPawns, int $fromProgress, int $target, int $excludePawnIndex): bool
    {
        $start = max(self::RING_LENGTH, $fromProgress + 1);
        for ($slot = $start; $slot <= $target; ++$slot) {
            if ($this->goalSlotTaken($ownPawns, $slot, $excludePawnIndex)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<int>> $pawns
     * @param array<string, int>       $seats
     *
     * @return list<int>
     */
    public function legalMoves(array $pawns, array $seats, string $playerId, int $roll, Options $options): array
    {
        $seat = $seats[$playerId];
        $own = $pawns[$playerId];
        $legal = [];

        foreach ($own as $index => $progress) {
            if (self::FINISH_PROGRESS === $progress) {
                continue;
            }

            if (-1 === $progress) {
                if (6 !== $roll) {
                    continue;
                }
                if ($options->enforceStartClearingWhilePawnInBase) {
                    $occupant = $this->pawnAtRingIndex($pawns, $seats, $this->startIndex($seat));
                    if (null !== $occupant && $occupant['playerId'] === $playerId) {
                        continue;
                    }
                }
                $legal[] = $index;

                continue;
            }

            $target = $progress + $roll;
            if ($target > self::FINISH_PROGRESS) {
                continue;
            }

            if ($target <= self::RING_LENGTH - 1) {
                $occupant = $this->pawnAtRingIndex($pawns, $seats, $this->ringIndexFor($seat, $target), $playerId, $index);
                if (null !== $occupant && $occupant['playerId'] === $playerId) {
                    continue;
                }
            } elseif ($options->allowGoalStretchOvertaking
                ? $this->goalSlotTaken($own, $target, $index)
                : $this->goalStretchBlocked($own, $progress, $target, $index)
            ) {
                continue;
            }

            $legal[] = $index;
        }

        // Enforced start clearing: while a pawn still sits in base and this player's own pawn
        // occupies the start square, that pawn takes priority - if it can move with this roll,
        // it's the ONLY legal move, forcing the player to clear the square before doing anything
        // else. If it can't move this roll (blocked or already excluded above), other pawns remain
        // free to move as usual.
        if ($options->enforceStartClearingWhilePawnInBase) {
            $startPawnIndex = array_search(0, $own, true);
            if (false !== $startPawnIndex && \in_array(-1, $own, true) && \in_array($startPawnIndex, $legal, true)) {
                return [$startPawnIndex];
            }
        }

        return $legal;
    }

    /**
     * @param array<string, list<int>> $pawns
     * @param array<string, int>       $seats
     */
    public function hasAnyLegalMove(array $pawns, array $seats, string $playerId, int $roll, Options $options): bool
    {
        return [] !== $this->legalMoves($pawns, $seats, $playerId, $roll, $options);
    }

    /**
     * Whether any pawn already on the board (ring or goal stretch - not base) would have a legal
     * move for AT LEAST ONE possible die value (1-6), independent of what was actually rolled.
     * Base-pawn releases never count here, even though rolling a six would free one - the retry
     * mechanic exists specifically to give the player more chances at that six.
     *
     * Used by the "no_legal_move" reroll rule to decide the number of allowed roll attempts.
     *
     * @param array<string, list<int>> $pawns
     * @param array<string, int>       $seats
     */
    public function hasAnyOnBoardTheoreticalMove(array $pawns, array $seats, string $playerId, Options $options): bool
    {
        $own = $pawns[$playerId];

        for ($roll = 1; $roll <= 6; ++$roll) {
            foreach ($this->legalMoves($pawns, $seats, $playerId, $roll, $options) as $pawnIndex) {
                if (-1 !== $own[$pawnIndex]) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the player has any pawn currently on the open ring track (not in base, not yet in
     * the goal stretch). Used by the "no_open_field" reroll rule - a pure presence check, no die
     * value involved.
     *
     * @param list<int> $ownPawns
     */
    public function hasAnyOpenFieldPawn(array $ownPawns): bool
    {
        return array_any($ownPawns, static fn (int $progress): bool => $progress >= 0 && $progress < self::RING_LENGTH);
    }

    /**
     * @param list<int> $ownPawns
     */
    public function hasWon(array $ownPawns): bool
    {
        return array_all($ownPawns, fn ($progress) => $progress >= self::RING_LENGTH);
    }
}
