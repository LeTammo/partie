<?php

declare(strict_types=1);

namespace App\Game\Games\Ludo;

final class GameRules
{
    public const int PAWNS_PER_PLAYER = 4;
    public const int RING_LENGTH = 40;
    public const int HOME_STEPS = 4;
    public const int FINISH_PROGRESS = self::RING_LENGTH + self::HOME_STEPS - 1;

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
    private function homeSlotTaken(array $ownPawns, int $progress, int $excludePawnIndex): bool
    {
        return array_any($ownPawns, fn($p, $pawnIndex) => $pawnIndex !== $excludePawnIndex && $p === $progress);

    }

    /**
     * @param array<string, list<int>> $pawns
     * @param array<string, int>       $seats
     *
     * @return list<int>
     */
    public function legalMoves(array $pawns, array $seats, string $playerId, int $roll): array
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
                $occupant = $this->pawnAtRingIndex($pawns, $seats, $this->startIndex($seat));
                if (null !== $occupant && $occupant['playerId'] === $playerId) {
                    continue;
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
            } elseif ($this->homeSlotTaken($own, $target, $index)) {
                continue;
            }

            $legal[] = $index;
        }

        return $legal;
    }

    /**
     * @param array<string, list<int>> $pawns
     * @param array<string, int>       $seats
     */
    public function hasAnyLegalMove(array $pawns, array $seats, string $playerId, int $roll): bool
    {
        return [] !== $this->legalMoves($pawns, $seats, $playerId, $roll);
    }

    /**
     * @param list<int> $ownPawns
     */
    public function hasWon(array $ownPawns): bool
    {
        return array_all($ownPawns, fn($progress) => self::FINISH_PROGRESS === $progress);

    }
}
