<?php

declare(strict_types=1);

namespace App\Game\Games\TicTacToe;

use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Token;
use App\Game\Core\View\BoardViews;

final class GameRenderer
{
    /**
     * @return array{grid: list<list<array{x: int, y: int, variant: ?string, color: ?string, playable: bool}>>}
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $myTurn = $state->isViewersTurn($viewerId);

        $grid = BoardViews::grid($state->board, static fn (int $x, int $y, ?Token $token): array => [
            'x' => $x,
            'y' => $y,
            'variant' => $token?->variant,
            'color' => $token?->outerColor,
            'playable' => $myTurn && null === $token && GameStatus::Running === $state->status,
        ]);

        return [
            'grid' => $grid,
            'myVariant' => null !== $viewerId ? ($state->data['variants'][$viewerId] ?? null) : null,
            'myColor' => null !== $viewerId ? $state->playerById($viewerId)?->color : null,
        ];
    }
}
