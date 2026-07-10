<?php

declare(strict_types=1);

namespace App\Game\Games\Ludo;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\Player;
use App\Game\Core\Service\AbstractGameDefinition;

final readonly class GameDefinition extends AbstractGameDefinition
{
    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'ludo';
    }

    public function getName(): string
    {
        return 'game.ludo.name';
    }

    public function getDescription(): string
    {
        return 'game.ludo.description';
    }

    public function getIcon(): string
    {
        return 'ludo';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 4;
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;
        $state->data['roll'] = null;
        $state->data['lastRoll'] = null;
        $state->data['rollAttempts'] = 0;

        foreach ($players as $player) {
            $state->data['pawns'][$player->id] = array_fill(0, GameRules::PAWNS_PER_PLAYER, -1);
        }

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'roll' => $this->roll($state),
            'move' => $this->move($state, $this->intParam($payload, 'pawn')),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/ludo/board.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function roll(GameState $state): void
    {
        if (null !== $state->data['roll']) {
            $this->invalidMove('error.ludo.already_rolled');
        }

        $player = $state->currentPlayer();
        $value = random_int(1, 6);
        $state->data['roll'] = $value;
        $state->data['lastRoll'] = $value;
        $state->logGameEvent('log.ludo.rolled', ['%player%' => $player->nickname, '%value%' => $value]);

        if ($this->rules->hasAnyLegalMove($state->data['pawns'], $this->seats($state), $player->id, $value)) {
            $state->data['rollAttempts'] = 0;

            return;
        }

        $state->logGameEvent('log.ludo.no_move', ['%player%' => $player->nickname]);
        $state->data['roll'] = null;

        if (6 === $value) {
            return;
        }

        $allInBase = array_all($state->data['pawns'][$player->id], static fn (int $progress): bool => -1 === $progress);
        if (
            ++$state->data['rollAttempts'] < 3
            && ($allInBase || !$this->rules->hasAnyLegalMove($state->data['pawns'], $this->seats($state), $player->id, $value))
        ) {
            return;
        }

        $state->data['rollAttempts'] = 0;
        $state->advanceTurn();
    }

    private function move(GameState $state, int $pawnIndex): void
    {
        $roll = $state->data['roll'];
        if (null === $roll) {
            $this->invalidMove('error.ludo.roll_first');
        }

        $player = $state->currentPlayer();
        $seats = $this->seats($state);
        $seat = $seats[$player->id];

        $legal = $this->rules->legalMoves($state->data['pawns'], $seats, $player->id, $roll);
        if (!\in_array($pawnIndex, $legal, true)) {
            $this->invalidMove('error.ludo.illegal_pawn');
        }

        $progress = &$state->data['pawns'][$player->id][$pawnIndex];

        if (-1 === $progress) {
            $progress = 0;
            $this->captureIfOpponent($state, $seats, $this->rules->startIndex($seat), $player);
            $state->logGameEvent('log.ludo.released', ['%player%' => $player->nickname]);
        } else {
            $target = $progress + $roll;
            if ($target <= GameRules::RING_LENGTH - 1) {
                $this->captureIfOpponent($state, $seats, $this->rules->ringIndexFor($seat, $target), $player);
            }
            $progress = $target;
            $state->logGameEvent('log.ludo.moved', ['%player%' => $player->nickname]);
        }
        unset($progress);

        $state->data['roll'] = null;

        if ($this->rules->hasWon($state->data['pawns'][$player->id])) {
            $state->finish($player->id);
            $state->logEvent('log.won', ['%player%' => $player->nickname]);

            return;
        }

        if (6 !== $roll) {
            $state->advanceTurn();
        }
    }

    /**
     * @param array<string, int> $seats
     */
    private function captureIfOpponent(GameState $state, array $seats, int $ringIndex, Player $player): void
    {
        $occupant = $this->rules->pawnAtRingIndex($state->data['pawns'], $seats, $ringIndex);
        if (null === $occupant || $occupant['playerId'] === $player->id) {
            return;
        }

        $state->data['pawns'][$occupant['playerId']][$occupant['pawnIndex']] = -1;
        $opponent = $state->playerById($occupant['playerId']);
        $state->logGameEvent('log.ludo.captured', [
            '%player%' => $player->nickname,
            '%opponent%' => $opponent->nickname,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function seats(GameState $state): array
    {
        $seats = [];
        foreach ($state->players as $p) {
            $seats[$p->id] = $p->seat;
        }

        return $seats;
    }
}
