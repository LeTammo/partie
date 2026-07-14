<?php

declare(strict_types=1);

namespace App\Game\Games\Battleships;

use App\Game\Core\Model\Board;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Player;
use App\Game\Core\Model\Token;
use App\Game\Core\View\BoardViews;
use App\Game\Core\View\PlayerViews;

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
        $running = GameStatus::Running === $state->status;
        $phase = $state->data['phase'];
        $myTurn = $state->isViewersTurn($viewerId);
        $totalCells = $state->data['totalCells'];

        $players = PlayerViews::build($state, static fn (Player $player): array => [
            'ready' => $state->data['ready'][$player->id],
            'hits' => \count($state->data['hits'][$player->id]),
            'totalCells' => $totalCells,
            'current' => 'battle' === $state->data['phase'] && $state->currentPlayer()->id === $player->id,
        ]);

        $interactive = 'placing' === $phase && null !== $viewerId && $running && !($state->data['ready'][$viewerId] ?? true);
        $ownFleet = null !== $viewerId && isset($state->data['fleets'][$viewerId])
            ? $this->fleetBoardView($state, $viewerId, $interactive)
            : null;

        $targetGrid = 'battle' === $phase && null !== $viewerId && isset($state->data['fleets'][$viewerId])
            ? $this->targetBoardView($state, $viewerId, $myTurn && $running)
            : null;

        $placing = null;
        if ('placing' === $phase && null !== $viewerId && isset($state->data['ready'][$viewerId])) {
            $placing = $this->placingView($state, $viewerId);
        }

        return [
            'phase' => $phase,
            'running' => $running,
            'myTurn' => $myTurn,
            'players' => $players,
            'ownFleet' => $ownFleet,
            'targetGrid' => $targetGrid,
            'placing' => $placing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function placingView(GameState $state, string $viewerId): array
    {
        /** @var Board $fleet */
        $fleet = $state->data['fleets'][$viewerId];
        $pool = $state->data['shapePool'];
        $placedSet = $state->data['placed'][$viewerId];

        $poolView = [];
        $shapesCatalog = [];
        foreach ($pool as $index => $shape) {
            $placed = isset($placedSet[$index]);
            $poolView[] = ['index' => $index, 'shape' => $shape, 'placed' => $placed];
            if (!isset($shapesCatalog[$shape])) {
                $shapesCatalog[$shape] = $this->rules->orientations($shape);
            }
        }

        $occupied = [];
        foreach ($fleet->tokens() as ['x' => $x, 'y' => $y]) {
            $occupied[] = "$x:$y";
        }

        return [
            'ready' => $state->data['ready'][$viewerId],
            'pool' => $poolView,
            'shapes' => $shapesCatalog,
            'occupied' => $occupied,
            'remaining' => \count($pool) - \count($placedSet),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fleetBoardView(GameState $state, string $viewerId, bool $interactive): array
    {
        /** @var Board $fleet */
        $fleet = $state->data['fleets'][$viewerId];
        $hits = $state->data['hits'][$viewerId];
        [$outer, $center] = GameRules::TOKEN_COLORS[$state->playerById($viewerId)?->seat ?? 0];

        $cells = [];
        foreach (BoardViews::grid($fleet, static fn (int $x, int $y, ?Token $token): array => ['x' => $x, 'y' => $y, 'token' => $token]) as $row) {
            foreach ($row as ['x' => $x, 'y' => $y, 'token' => $token]) {
                if (null === $token && $interactive) {
                    $cells[] = [
                        'class' => 'cursor-pointer grid size-6 place-items-center rounded-sm bg-softblue-50 transition touch-none sm:size-8',
                        'attr' => [
                            'data-battleships--placement-target' => 'cell',
                            'data-x' => $x,
                            'data-y' => $y,
                            'data-action' => 'pointerenter->battleships--placement#hover pointermove->battleships--placement#hover pointerleave->battleships--placement#leave click->battleships--placement#place',
                        ],
                    ];
                    continue;
                }

                $hit = null !== $token && \in_array("$x:$y", $hits, true);
                $cells[] = [
                    'class' => 'grid size-6 place-items-center rounded-sm sm:size-8 '.(null !== $token ? 'bg-white shadow-soft' : 'bg-softblue-50/60'),
                    'token' => null !== $token ? [
                        'outer' => $token->outerColor,
                        'center' => $token->centerColor,
                        'centerSize' => 45,
                        'size' => 'size-5 sm:size-7',
                        'icon' => $hit ? 'x' : null,
                        'symbolColor' => '#ffffff',
                        'ring' => $hit,
                        'class' => $hit ? 'ring-2 ring-terracotta-500' : '',
                    ] : null,
                ];
            }
        }

        return ['cols' => $fleet->width, 'rows' => $fleet->height, 'class' => 'grid gap-0.5 rounded-2xl bg-cream p-2', 'cells' => $cells];
    }

    /**
     * @return array<string, mixed>
     */
    private function targetBoardView(GameState $state, string $viewerId, bool $interactive): array
    {
        $opponent = $this->opponent($state, $viewerId);
        /** @var Board $fleet */
        $fleet = $state->data['fleets'][$opponent->id];
        /** @var Board $shots */
        $shots = $state->data['shots'][$viewerId];
        $hits = $state->data['hits'][$opponent->id];

        $cells = [];
        foreach (BoardViews::grid($shots, static fn (int $x, int $y, ?Token $token): array => ['x' => $x, 'y' => $y, 'token' => $token]) as $row) {
            foreach ($row as ['x' => $x, 'y' => $y, 'token' => $marker]) {
                if (null === $marker) {
                    $cells[] = $interactive
                        ? [
                            'form' => [
                                'fields' => ['action' => 'fire', 'x' => $x, 'y' => $y],
                                'buttonClass' => 'cursor-pointer grid size-6 place-items-center rounded-sm bg-softblue-50 transition hover:bg-softblue-100 sm:size-8',
                                'template' => ['shape' => 'plain', 'symbol' => '•', 'symbolColor' => 'var(--color-warmgray-300)', 'class' => 'anim-pop text-lg'],
                            ],
                        ]
                        : ['class' => 'grid size-6 place-items-center rounded-sm bg-softblue-50/60 sm:size-8'];
                    continue;
                }

                $sunk = false;
                if ('hit' === $marker->variant) {
                    $shipId = $fleet->get($x, $y)?->variant ?? '';
                    $sunk = '' !== $shipId && $this->rules->isSunk($fleet, $shipId, $hits);
                }

                $cells[] = [
                    'class' => 'grid size-6 place-items-center rounded-sm bg-white shadow-soft sm:size-8',
                    'token' => [
                        'outer' => $sunk ? '#a13f30' : $marker->outerColor,
                        'icon' => 'hit' === $marker->variant ? 'x' : null,
                        'symbolColor' => '#ffffff',
                        'size' => 'size-4 sm:size-5',
                        'shadow' => false,
                    ],
                ];
            }
        }

        return ['cols' => $shots->width, 'rows' => $shots->height, 'class' => 'grid gap-0.5 rounded-2xl bg-cream p-2', 'cells' => $cells];
    }

    private function opponent(GameState $state, string $playerId): Player
    {
        return $state->players[0]->id === $playerId ? $state->players[1] : $state->players[0];
    }
}
