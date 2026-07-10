<?php

declare(strict_types=1);

namespace App\Game\Games\Checkers;

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
     * @return array{
     *     grid: list<list<array<string, mixed>>>,
     *     moves: array<string, list<array{toX: int, toY: int}>>,
     *     mustContinue: bool
     * }
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $board = $state->board;
        $myTurn = $state->isViewersTurn($viewerId);

        $moves = [];
        $continueFrom = $state->data['mustContinueFrom'];

        if ($myTurn && GameStatus::Running === $state->status) {
            $direction = $state->data['directions'][$viewerId];

            if (null !== $continueFrom) {
                [$cx, $cy] = $continueFrom;
                $pieceMoves = $this->rules->movesForPiece($board, $cx, $cy, $direction, capturesOnly: true);
                if ([] !== $pieceMoves) {
                    $moves[$cx.':'.$cy] = array_map(
                        static fn (array $m): array => ['toX' => $m['toX'], 'toY' => $m['toY']],
                        $pieceMoves,
                    );
                }
            } else {
                foreach ($this->rules->allMovesFor($board, $viewerId, $direction) as $origin => $pieceMoves) {
                    $moves[$origin] = array_map(
                        static fn (array $m): array => ['toX' => $m['toX'], 'toY' => $m['toY']],
                        $pieceMoves,
                    );
                }
            }
        }

        $grid = BoardViews::grid($board, function (int $x, int $y, ?Token $token) use ($moves, $viewerId): array {
            return [
                'x' => $x,
                'y' => $y,
                'dark' => $this->rules->isDarkSquare($x, $y),
                'tokenId' => null !== $token && isset($token->id) ? $token->id : $x.'-'.$y,
                'outer' => $token?->outerColor,
                'inner' => $token?->innerColor,
                'king' => GameRules::KING === $token?->variant,
                'mine' => null !== $token && $token->ownerId === $viewerId,
                'selectable' => isset($moves[$x.':'.$y]),
            ];
        });

        return [
            'grid' => $grid,
            'moves' => $moves,
            'mustContinue' => $myTurn && null !== $continueFrom,
        ];
    }
}
