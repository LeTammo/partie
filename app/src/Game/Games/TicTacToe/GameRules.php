<?php

declare(strict_types=1);

namespace App\Game\Games\TicTacToe;

use App\Game\Core\Model\Board;

final class GameRules
{
    private const array LINES = [
        [[0, 0], [1, 0], [2, 0]],
        [[0, 1], [1, 1], [2, 1]],
        [[0, 2], [1, 2], [2, 2]],
        [[0, 0], [0, 1], [0, 2]],
        [[1, 0], [1, 1], [1, 2]],
        [[2, 0], [2, 1], [2, 2]],
        [[0, 0], [1, 1], [2, 2]],
        [[2, 0], [1, 1], [0, 2]],
    ];

    public function findWinner(Board $board): ?string
    {
        foreach (self::LINES as $line) {
            $owners = [];
            foreach ($line as [$x, $y]) {
                $owners[] = $board->get($x, $y)?->ownerId;
            }
            if (null !== $owners[0] && $owners[0] === $owners[1] && $owners[1] === $owners[2]) {
                return $owners[0];
            }
        }

        return null;
    }

    public function isBoardFull(Board $board): bool
    {
        return \count($board->tokens()) >= $board->width * $board->height;
    }
}
