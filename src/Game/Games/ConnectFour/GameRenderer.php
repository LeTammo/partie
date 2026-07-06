<?php

declare(strict_types=1);

namespace App\Game\Games\ConnectFour;

use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;

final readonly class GameRenderer
{
    public function __construct(private GameRules $rules)
    {
    }

    /**
     * @return array{grid: list<list<array{outer: ?string, inner: ?string}>>, columns: list<array{column: int, playable: bool}>}
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $myTurn = null !== $viewerId && $state->isPlayersTurn($viewerId);

        $grid = [];
        for ($y = 0; $y < $state->board->height; ++$y) {
            $row = [];
            for ($x = 0; $x < $state->board->width; ++$x) {
                $token = $state->board->get($x, $y);
                $row[] = [
                    'outer' => $token?->outerColor,
                    'inner' => $token?->innerColor,
                ];
            }
            $grid[] = $row;
        }

        $columns = [];
        for ($x = 0; $x < $state->board->width; ++$x) {
            $columns[] = [
                'column' => $x,
                'playable' => $myTurn
                    && GameStatus::Running === $state->status
                    && null !== $this->rules->dropRow($state->board, $x),
            ];
        }

        return ['grid' => $grid, 'columns' => $columns];
    }
}
