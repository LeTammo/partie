<?php

declare(strict_types=1);

namespace App\Game\Games\TicTacToe;

use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;

final class GameRenderer
{
    /**
     * @return array{grid: list<list<array{x: int, y: int, variant: ?string, color: ?string, playable: bool}>>}
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $myTurn = $state->isViewersTurn($viewerId);
        $grid = [];

        for ($y = 0; $y < $state->board->height; ++$y) {
            $row = [];
            for ($x = 0; $x < $state->board->width; ++$x) {
                $token = $state->board->get($x, $y);
                $row[] = [
                    'x' => $x,
                    'y' => $y,
                    'variant' => $token?->variant,
                    'color' => $token?->outerColor,
                    'playable' => $myTurn && null === $token && GameStatus::Running === $state->status,
                ];
            }
            $grid[] = $row;
        }

        return [
            'grid' => $grid,
            // lets the frontend place the viewer's symbol optimistically
            'myVariant' => null !== $viewerId ? ($state->data['variants'][$viewerId] ?? null) : null,
            'myColor' => null !== $viewerId ? $state->playerById($viewerId)?->color : null,
        ];
    }
}
