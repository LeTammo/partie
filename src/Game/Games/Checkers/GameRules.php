<?php

declare(strict_types=1);

namespace App\Game\Games\Checkers;

use App\Game\Core\Model\Board;
use App\Game\Core\Model\Token;

final class GameRules
{
    public const string KING = 'king';

    public function isDarkSquare(int $x, int $y): bool
    {
        return 1 === ($x + $y) % 2;
    }

    /**
     * @param int $direction +1 when the owner moves down the board, -1 up
     *
     * @return list<array{toX: int, toY: int, captureX: ?int, captureY: ?int}>
     */
    public function movesForPiece(Board $board, int $x, int $y, int $direction, bool $capturesOnly = false): array
    {
        $token = $board->get($x, $y);
        if (null === $token) {
            return [];
        }

        $isKing = self::KING === $token->variant;
        $rows = $isKing ? [1, -1] : [$direction];
        $moves = [];

        foreach ($rows as $dy) {
            foreach ([-1, 1] as $dx) {
                $stepX = $x + $dx;
                $stepY = $y + $dy;

                if (!$capturesOnly && $board->isEmpty($stepX, $stepY)) {
                    $moves[] = ['toX' => $stepX, 'toY' => $stepY, 'captureX' => null, 'captureY' => null];
                }

                $jumpX = $x + 2 * $dx;
                $jumpY = $y + 2 * $dy;
                $victim = $board->get($stepX, $stepY);

                if (null !== $victim && $victim->ownerId !== $token->ownerId && $board->isEmpty($jumpX, $jumpY)) {
                    $moves[] = ['toX' => $jumpX, 'toY' => $jumpY, 'captureX' => $stepX, 'captureY' => $stepY];
                }
            }
        }

        return $moves;
    }

    /**
     * @return array<string, list<array{toX: int, toY: int, captureX: ?int, captureY: ?int}>> keyed by "x:y" origin
     */
    public function allMovesFor(Board $board, string $playerId, int $direction): array
    {
        $all = [];
        foreach ($board->tokens() as ['x' => $x, 'y' => $y, 'token' => $token]) {
            if ($token->ownerId !== $playerId) {
                continue;
            }
            $moves = $this->movesForPiece($board, $x, $y, $direction);
            if ([] !== $moves) {
                $all[$x.':'.$y] = $moves;
            }
        }

        return $all;
    }

    public function canCaptureFrom(Board $board, int $x, int $y, int $direction): bool
    {
        return [] !== $this->movesForPiece($board, $x, $y, $direction, capturesOnly: true);
    }

    public function hasAnyMove(Board $board, string $playerId, int $direction): bool
    {
        return [] !== $this->allMovesFor($board, $playerId, $direction);
    }

    public function shouldPromote(Token $token, int $y, int $direction, int $boardHeight): bool
    {
        if (self::KING === $token->variant) {
            return false;
        }

        return (1 === $direction && $y === $boardHeight - 1) || (-1 === $direction && 0 === $y);
    }
}
