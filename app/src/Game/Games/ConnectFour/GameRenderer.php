<?php

declare(strict_types=1);

namespace App\Game\Games\ConnectFour;

use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Token;
use App\Game\Core\View\BoardViews;

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
        $myTurn = $state->isViewersTurn($viewerId);

        $grid = BoardViews::grid($state->board, static fn (int $x, int $y, ?Token $token): array => [
            'outer' => $token?->outerColor,
            'inner' => $token?->innerColor,
        ]);

        $columns = [];
        for ($x = 0; $x < $state->board->width; ++$x) {
            $columns[] = [
                'column' => $x,
                'playable' => $myTurn
                    && GameStatus::Running === $state->status
                    && null !== $this->rules->dropRow($state->board, $x),
            ];
        }

        $myColors = null !== $viewerId ? ($state->data['colors'][$viewerId] ?? null) : null;

        return [
            'grid' => $grid,
            'columns' => $columns,
            // lets the frontend drop the viewer's disc optimistically
            'myOuter' => $myColors[0] ?? null,
            'myInner' => $myColors[1] ?? null,
        ];
    }
}
