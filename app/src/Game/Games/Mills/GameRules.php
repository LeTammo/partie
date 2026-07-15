<?php

declare(strict_types=1);

namespace App\Game\Games\Mills;

use App\Game\Core\Model\Board;

// The 24-point Nine Men's Morris board: three nested squares (corners +
// edge midpoints) connected by four spokes. Point coordinates sit on a 7x7
// grid (same sparse-grid trick Ludo uses for its ring). How to use, see
// docs/adding_a_game.md.
final class GameRules
{
    public const int PIECES_PER_PLAYER = 9;
    public const int FLYING_AT = 3;

    /** @var list<array{0: string, 1: string}> per-seat [outerColor, centerColor] */
    public const array TOKEN_COLORS = [
        ['#a3b8a3', '#c9d8c9'], // sage green
        ['#b0a7c7', '#d4cde4'], // soft lavender
    ];

    /** @var list<array{0: int, 1: int}> point index -> [x, y] on the 7x7 grid */
    public const array POINTS = [
        [0, 0], [3, 0], [6, 0], [6, 3], [6, 6], [3, 6], [0, 6], [0, 3], // outer ring
        [1, 1], [3, 1], [5, 1], [5, 3], [5, 5], [3, 5], [1, 5], [1, 3], // middle ring
        [2, 2], [3, 2], [4, 2], [4, 3], [4, 4], [3, 4], [2, 4], [2, 3], // inner ring
    ];

    /** @var list<array{0: int, 1: int}> undirected adjacency edges (point index pairs) */
    public const array EDGES = [
        [0, 1], [1, 2], [2, 3], [3, 4], [4, 5], [5, 6], [6, 7], [7, 0],
        [8, 9], [9, 10], [10, 11], [11, 12], [12, 13], [13, 14], [14, 15], [15, 8],
        [16, 17], [17, 18], [18, 19], [19, 20], [20, 21], [21, 22], [22, 23], [23, 16],
        [1, 9], [9, 17], [3, 11], [11, 19], [5, 13], [13, 21], [7, 15], [15, 23],
    ];

    /** @var list<array{0: int, 1: int, 2: int}> the 16 mill lines (three point indexes each) */
    public const array MILLS = [
        [0, 1, 2], [2, 3, 4], [4, 5, 6], [6, 7, 0],
        [8, 9, 10], [10, 11, 12], [12, 13, 14], [14, 15, 8],
        [16, 17, 18], [18, 19, 20], [20, 21, 22], [22, 23, 16],
        [1, 9, 17], [3, 11, 19], [5, 13, 21], [7, 15, 23],
    ];

    public function pointIndexAt(int $x, int $y): ?int
    {
        foreach (self::POINTS as $index => [$px, $py]) {
            if ($px === $x && $py === $y) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function neighbors(int $point): array
    {
        $result = [];
        foreach (self::EDGES as [$a, $b]) {
            if ($a === $point) {
                $result[] = $b;
            } elseif ($b === $point) {
                $result[] = $a;
            }
        }

        return $result;
    }

    public function isAdjacent(int $a, int $b): bool
    {
        return \in_array($b, $this->neighbors($a), true);
    }

    public function formsMill(Board $board, int $point, string $ownerId): bool
    {
        foreach (self::MILLS as $mill) {
            if (!\in_array($point, $mill, true)) {
                continue;
            }
            if ($this->millOwnedBy($board, $mill, $ownerId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{0: int, 1: int, 2: int} $mill
     */
    private function millOwnedBy(Board $board, array $mill, string $ownerId): bool
    {
        foreach ($mill as $point) {
            [$x, $y] = self::POINTS[$point];
            $token = $board->get($x, $y);
            if (null === $token || $token->ownerId !== $ownerId) {
                return false;
            }
        }

        return true;
    }

    public function pointInMill(Board $board, int $point, string $ownerId): bool
    {
        foreach (self::MILLS as $mill) {
            if (\in_array($point, $mill, true) && $this->millOwnedBy($board, $mill, $ownerId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int> board point indexes of $ownerId's pieces that may be removed
     */
    public function removableCandidates(Board $board, string $ownerId): array
    {
        $all = [];
        $free = [];
        foreach (self::POINTS as $point => [$x, $y]) {
            $token = $board->get($x, $y);
            if (null === $token || $token->ownerId !== $ownerId) {
                continue;
            }
            $all[] = $point;
            if (!$this->pointInMill($board, $point, $ownerId)) {
                $free[] = $point;
            }
        }

        return [] !== $free ? $free : $all;
    }

    public function isFlying(Board $board, string $ownerId): bool
    {
        return $board->countTokensOf($ownerId) <= self::FLYING_AT;
    }

    /**
     * @return list<int> legal destination point indexes for a piece at $point
     */
    public function legalDestinations(Board $board, int $point, bool $flying): array
    {
        $candidates = $flying ? range(0, \count(self::POINTS) - 1) : $this->neighbors($point);

        return array_values(array_filter($candidates, function (int $target) use ($board, $point): bool {
            if ($target === $point) {
                return false;
            }
            [$x, $y] = self::POINTS[$target];

            return $board->isEmpty($x, $y);
        }));
    }

    public function hasAnyLegalMove(Board $board, string $ownerId, bool $flying): bool
    {
        foreach (self::POINTS as $point => [$x, $y]) {
            $token = $board->get($x, $y);
            if (null === $token || $token->ownerId !== $ownerId) {
                continue;
            }
            if ([] !== $this->legalDestinations($board, $point, $flying)) {
                return true;
            }
        }

        return false;
    }
}
