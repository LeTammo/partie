<?php

declare(strict_types=1);

namespace App\Game\Games\Battleships;

use App\Game\Core\Model\Board;

final class GameRules
{
    /** @var array<string, list<array{0: int, 1: int}>> canonical (orientation 0) cells per shape */
    public const array SHAPES = [
        'line2' => [[0, 0], [1, 0]],
        'line3' => [[0, 0], [1, 0], [2, 0]],
        'line4' => [[0, 0], [1, 0], [2, 0], [3, 0]],
        'line5' => [[0, 0], [1, 0], [2, 0], [3, 0], [4, 0]],
        'square4' => [[0, 0], [1, 0], [0, 1], [1, 1]],
        'square6' => [[0, 0], [1, 0], [0, 1], [1, 1], [0, 2], [1, 2]],
        'l' => [[0, 0], [0, 1], [0, 2], [1, 2]],
        'v' => [[0, 0], [0, 1], [0, 2], [1, 2], [2, 2]],
        's4' => [[0, 0], [1, 0], [1, 1], [2, 1]],
        's5' => [[0, 0], [1, 0], [1, 1], [1, 2], [2, 2]],
    ];

    /** @var list<string> display/placement order, biggest first */
    public const array SHAPE_ORDER = ['square6', 'line5', 'v', 's5', 'l', 'line4', 'square4', 's4', 'line3', 'line2'];

    /** @var array<string, int> classic Hasbro fleet, used when every shape count is 0 */
    public const array CLASSIC_POOL = ['line5' => 1, 'line4' => 1, 'line3' => 2, 'line2' => 1];

    /** @var list<array{0: string, 1: string}> per-seat [outerColor, centerColor] */
    public const array TOKEN_COLORS = [
        ['#a3b8a3', '#c9d8c9'], // sage green - seat 0
        ['#b0a7c7', '#d4cde4'], // soft lavender - seat 1
    ];

    /**
     * @param array<string, int> $counts shape key => count
     *
     * @return list<string> shape keys, one per ship to place, in display order
     */
    public function shapePool(array $counts): array
    {
        if (0 === array_sum($counts)) {
            $counts = self::CLASSIC_POOL;
        }

        $pool = [];
        foreach (self::SHAPE_ORDER as $shape) {
            for ($i = 0; $i < ($counts[$shape] ?? 0); ++$i) {
                $pool[] = $shape;
            }
        }

        return $pool;
    }

    /**
     * All unique orientations of a shape (rotations x mirror), normalized to
     * start at (0,0). The order is stable and is the single source of truth
     * for orientation indices used both server-side and by the client.
     *
     * @return list<list<array{0: int, 1: int}>>
     */
    public function orientations(string $shape): array
    {
        $result = [];
        $seen = [];

        foreach ([self::SHAPES[$shape], self::mirror(self::SHAPES[$shape])] as $cells) {
            $cells = self::normalize($cells);
            for ($i = 0; $i < 4; ++$i) {
                $signature = self::signature($cells);
                if (!isset($seen[$signature])) {
                    $seen[$signature] = true;
                    $result[] = $cells;
                }
                $cells = self::normalize(self::rotate($cells));
            }
        }

        return $result;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public function shapeCells(string $shape, int $orientationIndex, int $x, int $y): array
    {
        $cells = $this->orientations($shape)[$orientationIndex] ?? [];

        return array_map(static fn (array $c): array => [$c[0] + $x, $c[1] + $y], $cells);
    }

    /**
     * @param list<array{0: int, 1: int}> $cells normalized cells (min x/y = 0)
     *
     * @return array{0: int, 1: int} width, height
     */
    public function boundingBox(array $cells): array
    {
        return [max(array_column($cells, 0)) + 1, max(array_column($cells, 1)) + 1];
    }

    /**
     * @param list<array{0: int, 1: int}> $cells
     */
    public function canPlace(Board $fleet, array $cells): bool
    {
        foreach ($cells as [$x, $y]) {
            if (!$fleet->inBounds($x, $y) || !$fleet->isEmpty($x, $y)) {
                return false;
            }
        }

        return [] !== $cells;
    }

    /**
     * @return list<array{0: int, 1: int}> board coordinates belonging to $shipId
     */
    public function cellsOf(Board $fleet, string $shipId): array
    {
        $cells = [];
        foreach ($fleet->tokens() as ['x' => $x, 'y' => $y, 'token' => $token]) {
            if ($token->variant === $shipId) {
                $cells[] = [$x, $y];
            }
        }

        return $cells;
    }

    /**
     * @param list<string> $hits "x:y" keys already hit on this fleet
     */
    public function isSunk(Board $fleet, string $shipId, array $hits): bool
    {
        foreach ($this->cellsOf($fleet, $shipId) as [$x, $y]) {
            if (!\in_array("$x:$y", $hits, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{0: int, 1: int}> $cells
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function rotate(array $cells): array
    {
        return array_map(static fn (array $c): array => [-$c[1], $c[0]], $cells);
    }

    /**
     * @param list<array{0: int, 1: int}> $cells
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function mirror(array $cells): array
    {
        return array_map(static fn (array $c): array => [-$c[0], $c[1]], $cells);
    }

    /**
     * @param list<array{0: int, 1: int}> $cells
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function normalize(array $cells): array
    {
        $minX = min(array_column($cells, 0));
        $minY = min(array_column($cells, 1));

        return array_map(static fn (array $c): array => [$c[0] - $minX, $c[1] - $minY], $cells);
    }

    /**
     * @param list<array{0: int, 1: int}> $cells
     */
    private static function signature(array $cells): string
    {
        $keys = array_map(static fn (array $c): string => $c[0].':'.$c[1], $cells);
        sort($keys);

        return implode('|', $keys);
    }
}
