<?php

declare(strict_types=1);

namespace App\Game\Games\ConnectFour;

use App\Game\Core\Model\Board;

final class GameRules
{
    private const int CONNECT = 4;
    private const array DIRECTIONS = [[1, 0], [0, 1], [1, 1], [1, -1]];

    public function dropRow(Board $board, int $column): ?int
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

    public function isWinningMove(Board $board, int $x, int $y, int $connect = self::CONNECT): bool
    {
        $ownerId = $board->get($x, $y)?->ownerId;
        if (null === $ownerId) {
            return false;
        }

        foreach (self::DIRECTIONS as [$dx, $dy]) {
            $count = 1
                + $this->countDirection($board, $ownerId, $x, $y, $dx, $dy)
                + $this->countDirection($board, $ownerId, $x, $y, -$dx, -$dy);
            if ($count >= $connect) {
                return true;
            }
        }

        return false;
    }

    public function isBoardFull(Board $board): bool
    {
        return \count($board->tokens()) >= $board->width * $board->height;
    }

    private function countDirection(Board $board, string $ownerId, int $x, int $y, int $dx, int $dy): int
    {
        $count = 0;
        $x += $dx;
        $y += $dy;

        while ($board->inBounds($x, $y) && $board->get($x, $y)?->ownerId === $ownerId) {
            ++$count;
            $x += $dx;
            $y += $dy;
        }

        return $count;
    }
}
