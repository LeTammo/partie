<?php

declare(strict_types=1);

namespace App\Game\Core\View;

use App\Game\Core\Model\Board;
use App\Game\Core\Model\Token;

// How to use, see
// docs/components/tokens-and-boards.md
final class BoardViews
{
    /**
     * @param \Closure(int, int, ?Token): array<string, mixed> $cell
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function grid(Board $board, \Closure $cell): array
    {
        $grid = [];
        for ($y = 0; $y < $board->height; ++$y) {
            $row = [];
            for ($x = 0; $x < $board->width; ++$x) {
                $row[] = $cell($x, $y, $board->get($x, $y));
            }
            $grid[] = $row;
        }

        return $grid;
    }
}
