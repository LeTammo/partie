<?php

declare(strict_types=1);

namespace App\Game\Games\Mills;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\Board;
use App\Game\Core\Model\GameSetting;
use App\Game\Core\Model\GameSettingType;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\Player;
use App\Game\Core\Model\Token;
use App\Game\Core\Service\AbstractGameDefinition;
use App\Game\Core\View\MoveMap;

final readonly class GameDefinition extends AbstractGameDefinition
{
    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'mills';
    }

    public function getName(): string
    {
        return 'game.mills.name';
    }

    public function getDescription(): string
    {
        return 'game.mills.description';
    }

    public function getIcon(): string
    {
        return 'mills';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 2;
    }

    public function settings(): array
    {
        return [
            new GameSetting(
                key: 'flyingEnabled',
                labelKey: 'setting.mills.flying_enabled',
                type: GameSettingType::Bool,
                default: true,
            ),
        ];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $board = new Board(7, 7);
        $state = new GameState($this->getId(), $players, $board);
        $state->data['settings'] = $settings;
        $state->data['phase'] = 'placing';
        $state->data['placedCount'] = [$players[0]->id => 0, $players[1]->id => 0];
        $state->data['pendingRemoval'] = false;

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        if ($state->data['pendingRemoval']) {
            $this->remove($state, $playerId, $this->stringParam($payload, 'remove'));

            return;
        }

        match ($payload['action'] ?? '') {
            'place' => $this->place($state, $playerId, $this->intParam($payload, 'x'), $this->intParam($payload, 'y')),
            'move' => $this->move($state, $playerId, $this->stringParam($payload, 'from'), $this->stringParam($payload, 'to')),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/mills/board.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function place(GameState $state, string $playerId, int $x, int $y): void
    {
        if ('placing' !== $state->data['phase']) {
            $this->invalidMove('error.mills.not_placing');
        }
        if ($state->data['placedCount'][$playerId] >= GameRules::PIECES_PER_PLAYER) {
            $this->invalidMove('error.mills.already_placed_all');
        }

        $point = $this->rules->pointIndexAt($x, $y);
        if (null === $point || !$state->board->isEmpty($x, $y)) {
            $this->invalidMove('error.mills.invalid_point');
        }

        $player = $state->currentPlayer();
        [$outer, $center] = GameRules::TOKEN_COLORS[$player->seat];
        $state->board->place($x, $y, new Token(ownerId: $playerId, outerColor: $outer, centerColor: $center));
        ++$state->data['placedCount'][$playerId];

        $state->logGameEvent('log.mills.placed', ['%player%' => $player->nickname]);

        if ($this->bothFullyPlaced($state)) {
            $state->data['phase'] = 'moving';
        }

        $this->afterPieceLanded($state, $point, $player);
    }

    private function move(GameState $state, string $playerId, string $fromKey, string $toKey): void
    {
        if ('placing' === $state->data['phase']) {
            $this->invalidMove('error.mills.still_placing');
        }

        $from = MoveMap::coordsOf($fromKey);
        $to = MoveMap::coordsOf($toKey);
        if (null === $from || null === $to) {
            throw new InvalidMoveException('error.move_not_allowed');
        }
        [$fromX, $fromY] = $from;
        [$toX, $toY] = $to;

        $fromPoint = $this->rules->pointIndexAt($fromX, $fromY);
        $toPoint = $this->rules->pointIndexAt($toX, $toY);
        $token = $state->board->get($fromX, $fromY);

        if (null === $fromPoint || null === $toPoint || null === $token || $token->ownerId !== $playerId) {
            throw new InvalidMoveException('error.move_not_allowed');
        }

        $flying = $this->flyingAllowed($state) && $this->rules->isFlying($state->board, $playerId);
        if (!\in_array($toPoint, $this->rules->legalDestinations($state->board, $fromPoint, $flying), true)) {
            throw new InvalidMoveException('error.move_not_allowed');
        }

        $player = $state->currentPlayer();
        $state->board->move($fromX, $fromY, $toX, $toY);
        $state->logGameEvent('log.mills.moved', ['%player%' => $player->nickname]);

        $this->afterPieceLanded($state, $toPoint, $player);
    }

    private function afterPieceLanded(GameState $state, int $point, Player $player): void
    {
        if ($this->rules->formsMill($state->board, $point, $player->id)) {
            $state->data['pendingRemoval'] = true;
            $state->logGameEvent('log.mills.formed', ['%player%' => $player->nickname]);

            return;
        }

        $this->finishOrAdvance($state, $player);
    }

    private function remove(GameState $state, string $playerId, string $key): void
    {
        $coords = MoveMap::coordsOf($key);
        if (null === $coords) {
            throw new InvalidMoveException('error.move_not_allowed');
        }
        [$x, $y] = $coords;
        $point = $this->rules->pointIndexAt($x, $y);
        $target = $state->board->get($x, $y);
        $opponent = $this->opponent($state, $playerId);

        if (
            null === $point || null === $target || $target->ownerId !== $opponent->id
            || !\in_array($point, $this->rules->removableCandidates($state->board, $opponent->id), true)
        ) {
            $this->invalidMove('error.mills.choose_removal');
        }

        $state->board->remove($x, $y);
        $state->data['pendingRemoval'] = false;
        $state->logGameEvent('log.mills.removed', [
            '%player%' => $state->currentPlayer()->nickname,
            '%opponent%' => $opponent->nickname,
        ]);

        $this->finishOrAdvance($state, $state->currentPlayer());
    }

    private function finishOrAdvance(GameState $state, Player $player): void
    {
        $opponent = $this->opponent($state, $player->id);

        if ('moving' === $state->data['phase'] && $state->board->countTokensOf($opponent->id) < GameRules::FLYING_AT) {
            $state->finish($player->id);
            $state->logEvent('log.won', ['%player%' => $player->nickname]);

            return;
        }

        $state->advanceTurn();

        if ('moving' === $state->data['phase']) {
            $next = $state->currentPlayer();
            $flying = $this->flyingAllowed($state) && $this->rules->isFlying($state->board, $next->id);
            if (!$this->rules->hasAnyLegalMove($state->board, $next->id, $flying)) {
                $state->finish($player->id);
                $state->logEvent('log.won', ['%player%' => $player->nickname]);
            }
        }
    }

    private function bothFullyPlaced(GameState $state): bool
    {
        foreach ($state->data['placedCount'] as $count) {
            if ($count < GameRules::PIECES_PER_PLAYER) {
                return false;
            }
        }

        return true;
    }

    private function flyingAllowed(GameState $state): bool
    {
        return (bool) ($this->setting($state, 'flyingEnabled') ?? true);
    }

    private function opponent(GameState $state, string $playerId): Player
    {
        return $state->players[0]->id === $playerId ? $state->players[1] : $state->players[0];
    }
}
