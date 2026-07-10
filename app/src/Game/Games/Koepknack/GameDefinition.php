<?php

declare(strict_types=1);

namespace App\Game\Games\Koepknack;

use App\Game\Core\Card\DeckFactory;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Service\AbstractGameDefinition;

final readonly class GameDefinition extends AbstractGameDefinition
{
    private const int ROUNDS = 10;

    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'koepknack';
    }

    public function getName(): string
    {
        return 'game.koepknack.name';
    }

    public function getDescription(): string
    {
        return 'game.koepknack.description';
    }

    public function getIcon(): string
    {
        return 'cards';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 6;
    }

    public function createInitialState(array $players): GameState
    {
        $state = new GameState($this->getId(), $players);

        foreach ($players as $player) {
            $state->data['points'][$player->id] = 0;
        }
        $state->data['round'] = 0;
        $state->data['roundsTotal'] = self::ROUNDS;
        $state->data['starterIndex'] = 0;

        $this->startRound($state);

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if ('newround' === ($payload['action'] ?? null)) {
            $this->newRound($state);

            return;
        }

        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'swap' => $this->swap($state, $this->intParam($payload, 'hand'), $this->intParam($payload, 'middle')),
            'swapall' => $this->swapAll($state),
            'pass' => $this->pass($state),
            'close' => $this->close($state),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/koepknack/table.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function swap(GameState $state, int $handIndex, int $middleIndex): void
    {
        $this->assertPlaying($state);

        $playerId = $state->currentPlayer()->id;
        $hand = &$state->data['hands'][$playerId];

        if (!isset($hand[$handIndex]) || !isset($state->data['middle'][$middleIndex])) {
            $this->invalidMove('error.koepknack.select_cards');
        }

        [$hand[$handIndex], $state->data['middle'][$middleIndex]]
            = [$state->data['middle'][$middleIndex], $hand[$handIndex]];
        $state->data['passes'] = 0;

        $state->logGameEvent('log.koepknack.swapped', ['%player%' => $state->currentPlayer()->nickname]);
        $this->afterExchange($state);
    }

    private function swapAll(GameState $state): void
    {
        $this->assertPlaying($state);

        $playerId = $state->currentPlayer()->id;
        [$state->data['hands'][$playerId], $state->data['middle']]
            = [$state->data['middle'], $state->data['hands'][$playerId]];
        $state->data['passes'] = 0;

        $state->logGameEvent('log.koepknack.swapped_all', ['%player%' => $state->currentPlayer()->nickname]);
        $this->afterExchange($state);
    }

    private function pass(GameState $state): void
    {
        $this->assertPlaying($state);

        $state->logGameEvent('log.koepknack.passed', ['%player%' => $state->currentPlayer()->nickname]);

        if (null === $state->data['closerId']) {
            ++$state->data['passes'];
            if ($state->data['passes'] >= \count($state->players)) {
                if (\count($state->data['deck']) >= 3) {
                    $state->data['middle'] = array_splice($state->data['deck'], 0, 3);
                    $state->data['passes'] = 0;
                    $state->logGameEvent('log.koepknack.middle_refreshed');
                } else {
                    $this->endRound($state);

                    return;
                }
            }
        }

        $this->endTurn($state);
    }

    private function close(GameState $state): void
    {
        $this->assertPlaying($state);

        if (null !== $state->data['closerId']) {
            $this->invalidMove('error.koepknack.already_closed');
        }

        $state->data['closerId'] = $state->currentPlayer()->id;
        $state->logGameEvent('log.koepknack.closed', ['%player%' => $state->currentPlayer()->nickname]);
        $this->endTurn($state);
    }

    private function newRound(GameState $state): void
    {
        if ('roundend' !== ($state->data['phase'] ?? null)) {
            $this->invalidMove('error.koepknack.not_roundend');
        }

        $state->data['starterIndex'] = ($state->data['starterIndex'] + 1) % \count($state->players);
        $this->startRound($state);
    }

    private function assertPlaying(GameState $state): void
    {
        if ('playing' !== ($state->data['phase'] ?? null)) {
            $this->invalidMove('error.koepknack.round_over');
        }
    }

    private function afterExchange(GameState $state): void
    {
        $hand = $state->data['hands'][$state->currentPlayer()->id];
        $value = $this->rules->value($hand);

        if ($value >= GameRules::KNACK) {
            $state->logGameEvent(
                $this->rules->isFire($hand) ? 'log.koepknack.fire' : 'log.koepknack.knack',
                ['%player%' => $state->currentPlayer()->nickname],
            );
            $this->endRound($state);

            return;
        }

        $this->endTurn($state);
    }

    private function endTurn(GameState $state): void
    {
        $state->advanceTurn();

        if (null !== $state->data['closerId']
            && $state->currentPlayer()->id === $state->data['closerId']) {
            $this->endRound($state);
        }
    }

    private function endRound(GameState $state): void
    {
        $values = [];
        $fireIds = [];
        $summary = [];
        foreach ($state->players as $player) {
            $hand = $state->data['hands'][$player->id];
            $values[$player->id] = $this->rules->value($hand);
            if ($this->rules->isFire($hand)) {
                $fireIds[] = $player->id;
            }
            $summary[] = sprintf('%s: %s', $player->nickname, $this->rules->format($values[$player->id]));
        }
        $state->logGameEvent('log.koepknack.round_result', ['%values%' => implode(', ', $summary)]);

        $winnerIds = [] !== $fireIds ? $fireIds : array_keys($values, max($values), true);

        foreach ($winnerIds as $winnerId) {
            $winner = $state->playerById($winnerId);
            $points = ++$state->data['points'][$winnerId];
            $state->logGameEvent('log.koepknack.round_won', ['%player%' => $winner->nickname, '%points%' => $points]);
        }

        if ($state->data['round'] >= self::ROUNDS) {
            $this->finishGame($state);

            return;
        }

        $state->data['phase'] = 'roundend';
    }

    private function finishGame(GameState $state): void
    {
        $best = null;
        $bestPoints = -1;
        $tie = false;
        $summary = [];
        foreach ($state->players as $player) {
            $points = $state->data['points'][$player->id];
            $summary[] = sprintf('%s: %d', $player->nickname, $points);
            if ($points > $bestPoints) {
                $bestPoints = $points;
                $best = $player;
                $tie = false;
            } elseif ($points === $bestPoints) {
                $tie = true;
            }
        }

        $state->finish($tie ? null : $best?->id);
        $state->logGameEvent('log.koepknack.final', ['%points%' => implode(', ', $summary)]);
        if (!$tie && null !== $best) {
            $state->logGameEvent('log.koepknack.won', ['%player%' => $best->nickname, '%points%' => $bestPoints]);
        }
    }

    private function startRound(GameState $state): void
    {
        ++$state->data['round'];
        $state->data['phase'] = 'playing';

        $deck = DeckFactory::deck32();
        $state->data['hands'] = [];
        foreach ($state->players as $player) {
            $state->data['hands'][$player->id] = array_splice($deck, 0, 3);
        }
        $state->data['middle'] = array_splice($deck, 0, 3);
        $state->data['deck'] = $deck;
        $state->data['closerId'] = null;
        $state->data['passes'] = 0;
        $state->currentTurnIndex = $state->data['starterIndex'];

        $state->logGameEvent('log.koepknack.round_started', ['%round%' => $state->data['round']]);
    }
}
