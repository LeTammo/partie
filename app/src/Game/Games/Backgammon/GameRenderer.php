<?php

declare(strict_types=1);

namespace App\Game\Games\Backgammon;

use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Player;
use App\Game\Core\Model\Token;
use App\Game\Core\View\MoveMap;
use App\Game\Core\View\PlayerViews;
use App\Game\Core\Zone\Table;

final readonly class GameRenderer
{
    /** @var list<int> point indexes for each grid column, top row */
    private const array TOP_LEFT = [12, 13, 14, 15, 16, 17];
    private const array TOP_RIGHT = [18, 19, 20, 21, 22, 23];
    /** @var list<int> point indexes for each grid column, bottom row */
    private const array BOTTOM_LEFT = [11, 10, 9, 8, 7, 6];
    private const array BOTTOM_RIGHT = [5, 4, 3, 2, 1, 0];

    public function __construct(private GameRules $rules)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $table = $state->table;
        $running = GameStatus::Running === $state->status;
        $myTurn = $state->isViewersTurn($viewerId);
        $remainingDice = $state->data['remainingDice'];

        $moves = new MoveMap();
        if ($myTurn && $running && null !== $viewerId && [] !== $remainingDice) {
            $seat = $state->playerById($viewerId)->seat;
            foreach (array_unique($remainingDice) as $roll) {
                foreach ($this->rules->legalSourcesForRoll($table, $viewerId, $seat, $roll) as $from) {
                    $to = $this->rules->targetZoneFor($table, $viewerId, $seat, $from, $roll);
                    if (null !== $to) {
                        $moves->add($from, $to);
                    }
                }
            }
        }

        $cells = [];
        foreach (self::TOP_LEFT as $col => $point) {
            $cells[] = $this->pointCell($table, $point, 1, $col + 1, $viewerId, $moves);
        }
        $cells[] = $this->barCell($table, $state->players[0], 1, 7, $viewerId, $moves);
        foreach (self::TOP_RIGHT as $col => $point) {
            $cells[] = $this->pointCell($table, $point, 1, $col + 8, $viewerId, $moves);
        }
        foreach (self::BOTTOM_LEFT as $col => $point) {
            $cells[] = $this->pointCell($table, $point, 2, $col + 1, $viewerId, $moves);
        }
        $cells[] = $this->barCell($table, $state->players[1], 2, 7, $viewerId, $moves);
        foreach (self::BOTTOM_RIGHT as $col => $point) {
            $cells[] = $this->pointCell($table, $point, 2, $col + 8, $viewerId, $moves);
        }

        $players = PlayerViews::build($state, static fn (Player $player): array => [
            'off' => $table->zone('off:'.$player->id)->count(),
            'bar' => $table->zone('bar:'.$player->id)->count(),
        ]);

        $dice = array_map(static fn ($die) => $die->value, $state->dice);

        $allTargets = array_merge(...array_values($moves->toArray() ?: [[]]));
        $offTrays = [];
        foreach ($state->players as $player) {
            [$outer, $center] = GameRules::TOKEN_COLORS[$player->seat];
            $offTrays[] = [
                'playerId' => $player->id,
                'nickname' => $player->nickname,
                'count' => $table->zone('off:'.$player->id)->count(),
                'outer' => $outer,
                'center' => $center,
                'dropTarget' => $player->id === $viewerId && \in_array('off:'.$player->id, $allTargets, true),
            ];
        }

        return [
            'board' => [
                'cols' => 13,
                'rows' => 2,
                'class' => 'grid gap-1 rounded-3xl bg-cream p-3',
                'style' => 'width: min(94vw, 44rem);',
                'cells' => $cells,
            ],
            'moves' => $moves->toArray(),
            'players' => $players,
            'offTrays' => $offTrays,
            'myTurn' => $myTurn,
            'dice' => $dice,
            'remainingCount' => \count($remainingDice),
            'canRoll' => $myTurn && $running && [] === $remainingDice,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pointCell(Table $table, int $point, int $row, int $col, ?string $viewerId, MoveMap $moves): array
    {
        $key = 'point:'.$point;
        $items = $table->zone($key)->items;
        $shade = $point % 2 === 0 ? 'bg-warmgray-100/70' : 'bg-white/70';
        $topIndex = array_key_last($items);

        $tokens = [];
        foreach ($items as $i => $t) {
            $movable = $i === $topIndex && $t->ownerId === $viewerId && $moves->has($key);
            $tokens[] = $this->tokenView($t, $movable, $key);
        }

        return [
            'key' => $key,
            'style' => \sprintf('grid-row:%d;grid-column:%d;', $row, $col),
            'class' => 'flex h-36 justify-center rounded-xl sm:h-44 border border-warmgray-200 '.$shade,
            'tokens' => [] !== $tokens ? $tokens : null,
            'tokensMax' => 5,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function barCell(Table $table, Player $player, int $row, int $col, ?string $viewerId, MoveMap $moves): array
    {
        $key = 'bar:'.$player->id;
        $items = $table->zone($key)->items;
        $topIndex = array_key_last($items);

        $tokens = [];
        foreach ($items as $i => $t) {
            $movable = $i === $topIndex && $t->ownerId === $viewerId && $moves->has($key);
            $tokens[] = $this->tokenView($t, $movable, $key);
        }

        return [
            'key' => $key,
            'style' => \sprintf('grid-row:%d;grid-column:%d;', $row, $col),
            'class' => 'flex h-36 items-center justify-center rounded-xl bg-cream sm:h-44',
            'tokens' => [] !== $tokens ? $tokens : null,
            'tokensMax' => 5,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenView(Token $token, bool $movable, string $key): array
    {
        return [
            'outer' => $token->outerColor,
            'center' => $token->centerColor,
            'centerSize' => 45,
            'size' => 'size-7 sm:size-9',
            'ring' => $movable,
            'flip' => 'piece-'.$token->id,
            'exit' => 'fade',
            'class' => $movable ? 'cursor-grab' : '',
            'attr' => $movable ? [
                'data-source' => $key,
                'draggable' => 'true',
                'data-action' => 'dragstart->dragdrop--piece-move#dragStart dragend->dragdrop--piece-move#dragEnd',
            ] : [],
        ];
    }
}
