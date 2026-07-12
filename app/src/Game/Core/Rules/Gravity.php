<?php

declare(strict_types=1);

namespace App\Game\Core\Rules;

use App\Game\Core\Model\Board;

// Gravity drop for rack games: a piece dropped into a
// column lands on the lowest empty cell.
// How to use, see
// docs/components/tokens-and-boards.md
final class Gravity
{
    /** The row a piece dropped into $column would land on, or null if full. */
    public static function dropRow(Board $board, int $column): ?int
    {
        if ($column < 0 || $column >= $board->width) {
            return null;
        }

        for ($y = $board->height - 1; $y >= 0; --$y) {
            if (null === $board->get($column, $y)) {
                return $y;
            }
        }

        return null;
    }
}
