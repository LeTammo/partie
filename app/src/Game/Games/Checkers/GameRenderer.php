<?php

declare(strict_types=1);

namespace App\Game\Games\Checkers;

use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Token;
use App\Game\Core\View\BoardViews;
use App\Game\Core\View\MoveMap;

final readonly class GameRenderer
{
    public function __construct(private GameRules $rules)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $board = $state->board;
        $myTurn = $state->isViewersTurn($viewerId);
        $continueFrom = $state->data['mustContinueFrom'];
        $pendingSacrifice = $myTurn ? $state->data['pendingSacrifice'] : null;

        $moves = new MoveMap();
        if ($myTurn && GameStatus::Running === $state->status && null === $pendingSacrifice) {
            $direction = $state->data['directions'][$viewerId];

            if (null !== $continueFrom) {
                [$cx, $cy] = $continueFrom;
                foreach ($this->rules->movesForPiece($board, $cx, $cy, $direction, capturesOnly: true) as $move) {
                    $moves->add(MoveMap::cellKey($cx, $cy), MoveMap::cellKey($move['toX'], $move['toY']));
                }
            } else {
                foreach ($this->rules->allMovesFor($board, $viewerId, $direction) as $origin => $pieceMoves) {
                    [$x, $y] = array_map(intval(...), explode(':', $origin));
                    foreach ($pieceMoves as $move) {
                        $moves->add(MoveMap::cellKey($x, $y), MoveMap::cellKey($move['toX'], $move['toY']));
                    }
                }
            }
        }

        $sacrificeSquares = null !== $pendingSacrifice
            ? array_map(static fn (array $s): string => MoveMap::cellKey($s[0], $s[1]), $pendingSacrifice)
            : [];

        $cells = [];
        foreach (BoardViews::grid($board, fn (int $x, int $y, ?Token $token): array => [
            'x' => $x, 'y' => $y, 'token' => $token,
        ]) as $row) {
            foreach ($row as ['x' => $x, 'y' => $y, 'token' => $token]) {
                $key = MoveMap::cellKey($x, $y);
                $selectable = $moves->has($key);
                $sacrifice = \in_array($key, $sacrificeSquares, true);
                $mine = null !== $token && $token->ownerId === $viewerId;
                $movable = $mine && !$sacrifice && $selectable;

                $cells[] = [
                    'tag' => 'button',
                    'key' => $key,
                    'attr' => (!$selectable && !$sacrifice && null === $token) ? ['tabindex' => '-1'] : [],
                    'class' => 'relative grid size-10 place-items-center sm:size-14 '
                        .($this->rules->isDarkSquare($x, $y) ? 'bg-warmgray-300/80' : 'bg-cream').' '
                        .($selectable || $sacrifice ? 'cursor-pointer hover:brightness-105' : 'cursor-default'),
                    'token' => null !== $token ? [
                        'outer' => $token->outerColor,
                        'center' => $token->centerColor,
                        'centerSize' => 45,
                        'icon' => GameRules::KING === $token->variant ? 'crown' : null,
                        'symbolColor' => $token->centerColor,
                        'overlayIcon' => $sacrifice ? 'x' : null,
                        'flip' => 'piece-'.$token->id,
                        'exit' => 'fade',
                        'size' => 'size-8 sm:size-11',
                        'ring' => $selectable,
                        'class' => 'group'
                            .($sacrifice ? ' ring-2 ring-terracotta-500' : '')
                            .($mine && !$sacrifice ? ' cursor-grab' : ''),
                        'attr' => $movable ? [
                            'data-source' => $key,
                            'draggable' => 'true',
                            'data-action' => 'dragstart->dragdrop--piece-move#dragStart dragend->dragdrop--piece-move#dragEnd',
                        ] : [],
                    ] : null,
                ];
            }
        }

        return [
            'board' => [
                'cols' => $board->width,
                'rows' => $board->height,
                'class' => 'grid overflow-hidden rounded-3xl shadow-soft',
                'cells' => $cells,
            ],
            'moves' => $moves->toArray(),
            'sacrificeSquares' => $sacrificeSquares,
            'mustContinue' => $myTurn && null !== $continueFrom,
            'pendingSacrifice' => null !== $pendingSacrifice,
        ];
    }
}
