<?php

declare(strict_types=1);

namespace App\Game\Games\Backgammon;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\Dice;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\Player;
use App\Game\Core\Model\Token;
use App\Game\Core\Service\AbstractGameDefinition;
use App\Game\Core\Zone\Table;
use App\Game\Core\Zone\Zone;

final readonly class GameDefinition extends AbstractGameDefinition
{
    /** @var list<array{0: int, 1: int}> own-frame starting point (n) -> checker count */
    private const array STARTING_LAYOUT = [[23, 2], [12, 5], [7, 3], [5, 5]];

    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'backgammon';
    }

    public function getName(): string
    {
        return 'game.backgammon.name';
    }

    public function getDescription(): string
    {
        return 'game.backgammon.description';
    }

    public function getIcon(): string
    {
        return 'games/backgammon';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 2;
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;
        $table = $state->table = new Table();

        foreach ($players as $player) {
            $table->add(new Zone('bar:'.$player->id, $player->id));
            $table->add(new Zone('off:'.$player->id, $player->id));
        }
        for ($point = 0; $point < GameRules::POINTS; ++$point) {
            $table->add(new Zone('point:'.$point));
        }

        foreach ($players as $player) {
            $seat = $player->seat;
            [$outer, $center] = GameRules::TOKEN_COLORS[$seat];
            foreach (self::STARTING_LAYOUT as [$ownFrame, $count]) {
                $absolute = 0 === $seat ? $ownFrame : GameRules::POINTS - 1 - $ownFrame;
                for ($i = 0; $i < $count; ++$i) {
                    $table->zone('point:'.$absolute)->push(
                        new Token(ownerId: $player->id, outerColor: $outer, centerColor: $center)
                    );
                }
            }
        }

        $state->data['remainingDice'] = [];

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'roll' => $this->roll($state),
            'move' => $this->move(
                $state,
                $playerId,
                $this->stringParam($payload, 'from'),
                $this->stringParam($payload, 'to')
            ),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/backgammon/board.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function roll(GameState $state): void
    {
        if ([] !== $state->data['remainingDice']) {
            $this->invalidMove('error.backgammon.already_rolled');
        }

        $player = $state->currentPlayer();
        $state->dice = [new Dice(), new Dice()];
        $a = $state->dice[0]->roll();
        $b = $state->dice[1]->roll();

        $state->data['remainingDice'] = $a === $b ? [$a, $a, $a, $a] : [$a, $b];
        $state->logGameEvent('log.backgammon.rolled', ['%player%' => $player->nickname, '%a%' => $a, '%b%' => $b]);

        $this->endTurnIfStuck($state, $player);
    }

    private function move(GameState $state, string $playerId, string $from, string $to): void
    {
        if ([] === $state->data['remainingDice']) {
            $this->invalidMove('error.backgammon.roll_first');
        }

        $player = $state->currentPlayer();
        $seat = $player->seat;
        $table = $state->table;

        $usedRoll = null;
        foreach (array_unique($state->data['remainingDice']) as $roll) {
            if ($this->rules->targetZoneFor($table, $playerId, $seat, $from, $roll) === $to) {
                $usedRoll = $roll;
                break;
            }
        }

        if (null === $usedRoll) {
            throw new InvalidMoveException('error.move_not_allowed');
        }

        $checker = $table->zone($from)->pop();
        if (null === $checker) {
            throw new InvalidMoveException('error.move_not_allowed');
        }

        if (str_starts_with($to, 'point:')) {
            $destination = $table->zone($to);
            if (1 === $destination->count() && $destination->items[0]->ownerId !== $playerId) {
                $hit = $destination->clear()[0];
                $table->zone('bar:'.$hit->ownerId)->push($hit);
                $state->logGameEvent('log.backgammon.hit', ['%player%' => $player->nickname]);
            }
        }
        $table->zone($to)->push($checker);

        $index = array_search($usedRoll, $state->data['remainingDice'], true);
        unset($state->data['remainingDice'][$index]);
        $state->data['remainingDice'] = array_values($state->data['remainingDice']);

        $state->logGameEvent('log.backgammon.moved', ['%player%' => $player->nickname]);

        if (GameRules::CHECKERS_PER_PLAYER === $table->zone('off:'.$playerId)->count()) {
            $state->finish($playerId);
            $state->logEvent('log.won', ['%player%' => $player->nickname]);

            return;
        }

        $this->endTurnIfStuck($state, $player);
    }

    private function endTurnIfStuck(GameState $state, Player $player): void
    {
        if ([] === $state->data['remainingDice']) {
            $state->advanceTurn();

            return;
        }

        if (!$this->rules->hasAnyLegalMove($state->table, $player->id, $player->seat, $state->data['remainingDice'])) {
            $state->logGameEvent('log.backgammon.no_moves', ['%player%' => $player->nickname]);
            $state->data['remainingDice'] = [];
            $state->advanceTurn();
        }
    }
}
