<?php

declare(strict_types=1);

namespace App\Game\Games\Mills;

use App\Game\Core\Model\Board;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Player;
use App\Game\Core\Model\Token;
use App\Game\Core\View\MoveMap;
use App\Game\Core\View\PlayerViews;

final readonly class GameRenderer
{
    private const int SIZE = 7;
    private const string LINE_COLOR = 'var(--color-warmgray-300)';

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
        $running = GameStatus::Running === $state->status;
        $phase = $state->data['phase'];
        $pendingRemoval = $state->data['pendingRemoval'] && $myTurn && $running;

        $moves = new MoveMap();
        $choices = [];

        if ($pendingRemoval && null !== $viewerId) {
            $opponent = $this->opponent($state, $viewerId);
            foreach ($this->rules->removableCandidates($board, $opponent->id) as $point) {
                [$x, $y] = GameRules::POINTS[$point];
                $choices[] = MoveMap::cellKey($x, $y);
            }
        } elseif ('moving' === $phase && $myTurn && $running) {
            $flying = $this->flyingAllowed($state) && $this->rules->isFlying($board, $viewerId);
            foreach (GameRules::POINTS as $point => [$x, $y]) {
                $token = $board->get($x, $y);
                if (null === $token || $token->ownerId !== $viewerId) {
                    continue;
                }
                foreach ($this->rules->legalDestinations($board, $point, $flying) as $target) {
                    [$tx, $ty] = GameRules::POINTS[$target];
                    $moves->add(MoveMap::cellKey($x, $y), MoveMap::cellKey($tx, $ty));
                }
            }
        }

        $cells = [];
        for ($y = 0; $y < self::SIZE; ++$y) {
            for ($x = 0; $x < self::SIZE; ++$x) {
                $point = $this->rules->pointIndexAt($x, $y);
                if (null === $point) {
                    continue;
                }
                $cells[] = $this->pointCell($state, $board, $x, $y, $viewerId, $phase, $pendingRemoval, $moves, $choices, $myTurn && $running);
            }
        }

        $players = PlayerViews::build($state, static fn (Player $player): array => [
            'pieces' => $board->countTokensOf($player->id),
        ]);

        return [
            'board' => [
                'cols' => self::SIZE,
                'rows' => self::SIZE,
                'class' => 'relative rounded-3xl bg-cream p-4 mt-10 mb-8',
                'style' => 'width: min(92vw, 30rem); aspect-ratio: 1;',
                'layers' => $this->layers(),
                'cells' => $cells,
            ],
            'moves' => $moves->toArray(),
            'choices' => $choices,
            'players' => $players,
            'phase' => $phase,
            'myTurn' => $myTurn,
            'pendingRemoval' => $pendingRemoval,
            'opponentPendingRemoval' => $state->data['pendingRemoval'] && !$myTurn && $running,
            'placedRemaining' => null !== $viewerId ? GameRules::PIECES_PER_PLAYER - ($state->data['placedCount'][$viewerId] ?? 0) : 0,
        ];
    }

    /**
     * @param list<string> $choices
     *
     * @return array<string, mixed>
     */
    private function pointCell(
        GameState $state,
        Board $board,
        int $x,
        int $y,
        ?string $viewerId,
        string $phase,
        bool $pendingRemoval,
        MoveMap $moves,
        array $choices,
        bool $interactive,
    ): array {
        $key = MoveMap::cellKey($x, $y);
        $token = $board->get($x, $y);
        $base = 'relative grid size-9 place-items-center rounded-full bg-white shadow-md shadow-warmgray-500 sm:size-11 ';
        $style = \sprintf(
            'position:absolute;left:%s%%;top:%s%%;transform:translate(-50%%,-50%%);',
            $this->pct($x),
            $this->pct($y),
        );

        if ($pendingRemoval) {
            $removable = \in_array($key, $choices, true);

            return [
                'key' => $removable ? $key : null,
                'style' => $style,
                'class' => $base.($removable ? ' cursor-pointer ring-2 ring-terracotta-300 hover:brightness-105' : ''),
                'token' => $this->tokenView($token, false, $removable),
            ];
        }

        if ('placing' === $phase) {
            if (null === $token && $interactive && $state->data['placedCount'][$viewerId] < GameRules::PIECES_PER_PLAYER) {
                $seat = $state->playerById($viewerId)?->seat ?? 0;
                [$outer, $center] = GameRules::TOKEN_COLORS[$seat];

                return [
                    'style' => $style,
                    'form' => [
                        'fields' => ['action' => 'place', 'x' => $x, 'y' => $y],
                        'buttonClass' => $base.' transition hover:bg-softblue-50',
                        'template' => ['outer' => $outer, 'center' => $center, 'centerSize' => 45, 'size' => 'size-7 sm:size-9'],
                    ],
                ];
            }

            return ['style' => $style, 'class' => $base, 'token' => $this->tokenView($token)];
        }

        $movable = $interactive && null !== $token && $token->ownerId === $viewerId && $moves->has($key);
        $tokenView = $this->tokenView($token, $movable);
        if ($movable) {
            $tokenView['attr'] = [
                'data-source' => $key,
                'draggable' => 'true',
                'data-action' => 'dragstart->dragdrop--piece-move#dragStart dragend->dragdrop--piece-move#dragEnd',
            ];
        }

        return [
            'key' => $key,
            'style' => $style,
            'class' => $base,
            'token' => $tokenView,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tokenView(?Token $token, bool $movable = false, bool $removable = false): ?array
    {
        if (null === $token) {
            return null;
        }

        return [
            'outer' => $token->outerColor,
            'center' => $token->centerColor,
            'centerSize' => 45,
            'size' => 'size-7 sm:size-9',
            'ring' => $movable,
            'overlayIcon' => $removable ? 'x' : null,
            'flip' => 'piece-'.$token->id,
            'exit' => 'fade',
            'class' => 'group'.($movable ? ' cursor-grab' : '').($removable ? ' ring-2 ring-terracotta-500' : ''),
        ];
    }

    /**
     * @return list<array{style: string, class?: string}>
     */
    private function layers(): array
    {
        $layers = [];
        foreach ([0, 1, 2] as $ring) {
            $near = $this->pct($ring);
            $size = $this->pct(self::SIZE - 1 - 2 * $ring);
            $layers[] = [
                'style' => \sprintf(
                    'position:absolute;left:%1$s%%;top:%1$s%%;width:%2$s%%;height:%2$s%%;border:3px solid %3$s;border-radius:0.75rem;',
                    $near,
                    $size,
                    self::LINE_COLOR,
                ),
            ];
        }

        $mid = $this->pct((self::SIZE - 1) / 2);
        $armEnd = $this->pct(self::SIZE - 3);
        $armLen = $this->pct(2);

        // top / bottom spokes: vertical lines at x = mid
        $layers[] = ['style' => \sprintf('position:absolute;left:%1$s%%;top:0%%;width:3px;height:%2$s%%;background-color:%3$s;transform:translateX(-50%%);', $mid, $armLen, self::LINE_COLOR)];
        $layers[] = ['style' => \sprintf('position:absolute;left:%1$s%%;top:%2$s%%;width:3px;height:%3$s%%;background-color:%4$s;transform:translateX(-50%%);', $mid, $armEnd, $armLen, self::LINE_COLOR)];

        // left / right spokes: horizontal lines at y = mid
        $layers[] = ['style' => \sprintf('position:absolute;top:%1$s%%;left:0%%;height:3px;width:%2$s%%;background-color:%3$s;transform:translateY(-50%%);', $mid, $armLen, self::LINE_COLOR)];
        $layers[] = ['style' => \sprintf('position:absolute;top:%1$s%%;left:%2$s%%;height:3px;width:%3$s%%;background-color:%4$s;transform:translateY(-50%%);', $mid, $armEnd, $armLen, self::LINE_COLOR)];

        return $layers;
    }

    private function pct(int|float $coord): string
    {
        return \sprintf('%.3F', $coord / (self::SIZE - 1) * 100);
    }

    private function flyingAllowed(GameState $state): bool
    {
        $settings = $state->data['settings'] ?? [];

        return (bool) ($settings['flyingEnabled'] ?? true);
    }

    private function opponent(GameState $state, string $playerId): Player
    {
        return $state->players[0]->id === $playerId ? $state->players[1] : $state->players[0];
    }
}
