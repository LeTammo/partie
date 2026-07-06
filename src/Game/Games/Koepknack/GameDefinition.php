<?php

declare(strict_types=1);

namespace App\Game\Games\Koepknack;

use App\Game\Core\Card\DeckFactory;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\Player;
use App\Game\Core\Service\GameEngineInterface;

final class GameDefinition implements GameEngineInterface
{
    private const int LIVES = 3;

    public function __construct(
        private readonly GameRules $rules,
        private readonly GameRenderer $renderer,
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
            $state->data['lives'][$player->id] = self::LIVES;
            $state->data['swimming'][$player->id] = false;
            $state->data['eliminated'][$player->id] = false;
        }
        $state->data['round'] = 0;
        $state->data['starterIndex'] = 0;

        $this->startRound($state);

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'swap' => $this->swap($state, (int) ($payload['hand'] ?? -1), (int) ($payload['middle'] ?? -1)),
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
        $playerId = $state->currentPlayer()->id;
        $hand = &$state->data['hands'][$playerId];

        if (!isset($hand[$handIndex]) || !isset($state->data['middle'][$middleIndex])) {
            throw new InvalidMoveException('error.koepknack.select_cards', domain: 'koepknack');
        }

        [$hand[$handIndex], $state->data['middle'][$middleIndex]]
            = [$state->data['middle'][$middleIndex], $hand[$handIndex]];
        $state->data['passes'] = 0;

        $state->logGameEvent('log.koepknack.swapped', ['%player%' => $state->currentPlayer()->nickname]);
        $this->afterExchange($state);
    }

    private function swapAll(GameState $state): void
    {
        $playerId = $state->currentPlayer()->id;
        [$state->data['hands'][$playerId], $state->data['middle']]
            = [$state->data['middle'], $state->data['hands'][$playerId]];
        $state->data['passes'] = 0;

        $state->logGameEvent('log.koepknack.swapped_all', ['%player%' => $state->currentPlayer()->nickname]);
        $this->afterExchange($state);
    }

    private function pass(GameState $state): void
    {
        $state->logGameEvent('log.koepknack.passed', ['%player%' => $state->currentPlayer()->nickname]);

        if (null === $state->data['closerId']) {
            ++$state->data['passes'];
            if ($state->data['passes'] >= \count($this->activePlayers($state))) {
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
        if (null !== $state->data['closerId']) {
            throw new InvalidMoveException('error.koepknack.already_closed', domain: 'koepknack');
        }

        $state->data['closerId'] = $state->currentPlayer()->id;
        $state->logGameEvent('log.koepknack.closed', ['%player%' => $state->currentPlayer()->nickname]);
        $this->endTurn($state);
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
        $this->advanceToNextActive($state);

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
        foreach ($this->activePlayers($state) as $player) {
            $hand = $state->data['hands'][$player->id];
            $values[$player->id] = $this->rules->value($hand);
            if ($this->rules->isFire($hand)) {
                $fireIds[] = $player->id;
            }
            $summary[] = sprintf('%s: %s', $player->nickname, $this->rules->format($values[$player->id]));
        }
        $state->logGameEvent('log.koepknack.round_result', ['%values%' => implode(', ', $summary)]);

        if ([] !== $fireIds) {
            $loserIds = array_keys(array_diff_key($values, array_flip($fireIds)));
        } else {
            $min = min($values);
            $loserIds = array_keys(array_filter($values, static fn (float $v): bool => $v <= $min));
        }

        foreach ($loserIds as $loserId) {
            $loser = $state->playerById($loserId);
            if ($state->data['swimming'][$loserId]) {
                $state->data['eliminated'][$loserId] = true;
                $state->logGameEvent('log.koepknack.eliminated', ['%player%' => $loser->nickname]);
            } elseif (0 === --$state->data['lives'][$loserId]) {
                $state->data['swimming'][$loserId] = true;
                $state->logGameEvent('log.koepknack.swims', ['%player%' => $loser->nickname]);
            } else {
                $state->logGameEvent('log.koepknack.lost_life', [
                    '%player%' => $loser->nickname,
                    '%lives%' => $state->data['lives'][$loserId],
                ]);
            }
        }

        $active = $this->activePlayers($state);
        if (\count($active) <= 1) {
            $winner = $active[0] ?? null;
            $state->finish($winner?->id);
            if (null !== $winner) {
                $state->logGameEvent('log.koepknack.won', ['%player%' => $winner->nickname]);
            }

            return;
        }

        $state->data['starterIndex'] = ($state->data['starterIndex'] + 1) % \count($state->players);
        $this->startRound($state);
    }

    private function startRound(GameState $state): void
    {
        ++$state->data['round'];

        $deck = DeckFactory::deck32();
        $state->data['hands'] = [];
        foreach ($state->players as $player) {
            if (!$state->data['eliminated'][$player->id]) {
                $state->data['hands'][$player->id] = array_splice($deck, 0, 3);
            }
        }
        $state->data['middle'] = array_splice($deck, 0, 3);
        $state->data['deck'] = $deck;
        $state->data['closerId'] = null;
        $state->data['passes'] = 0;

        $state->currentTurnIndex = $state->data['starterIndex'];
        if ($state->data['eliminated'][$state->currentPlayer()->id]) {
            $this->advanceToNextActive($state);
        }
        $state->data['starterIndex'] = $state->currentTurnIndex;

        $state->logGameEvent('log.koepknack.round_started', ['%round%' => $state->data['round']]);
    }

    private function advanceToNextActive(GameState $state): void
    {
        do {
            $state->advanceTurn();
        } while ($state->data['eliminated'][$state->currentPlayer()->id]);
    }

    /**
     * @return list<Player>
     */
    private function activePlayers(GameState $state): array
    {
        return array_values(array_filter(
            $state->players,
            fn ($player): bool => !$state->data['eliminated'][$player->id],
        ));
    }
}
