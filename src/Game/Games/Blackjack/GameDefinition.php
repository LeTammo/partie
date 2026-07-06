<?php

declare(strict_types=1);

namespace App\Game\Games\Blackjack;

use App\Game\Core\Card\DeckFactory;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\Player;
use App\Game\Core\Service\GameEngineInterface;

final class GameDefinition implements GameEngineInterface
{
    private const int START_CHIPS = 100;
    private const int ROUNDS = 5;

    public function __construct(
        private readonly GameRules $rules,
        private readonly GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'blackjack';
    }

    public function getName(): string
    {
        return 'game.blackjack.name';
    }

    public function getDescription(): string
    {
        return 'game.blackjack.description';
    }

    public function getIcon(): string
    {
        return 'chip';
    }

    public function getMinPlayers(): int
    {
        return 1;
    }

    public function getMaxPlayers(): int
    {
        return 5;
    }

    public function createInitialState(array $players): GameState
    {
        $state = new GameState($this->getId(), $players);

        foreach ($players as $player) {
            $state->data['chips'][$player->id] = self::START_CHIPS;
        }
        $state->data['round'] = 0;
        $state->data['roundsTotal'] = self::ROUNDS;

        $this->startRound($state);

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'bet' => $this->bet($state, (int) ($payload['amount'] ?? 0)),
            'hit' => $this->hit($state),
            'stand' => $this->stand($state),
            'double' => $this->double($state),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/blackjack/table.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function bet(GameState $state, int $amount): void
    {
        if ('betting' !== $state->data['phase']) {
            throw new InvalidMoveException('error.blackjack.no_betting', domain: 'blackjack');
        }

        $player = $state->currentPlayer();
        if (!\in_array($amount, GameRules::BET_OPTIONS, true)
            || $amount > $state->data['chips'][$player->id]) {
            throw new InvalidMoveException('error.blackjack.invalid_bet', domain: 'blackjack');
        }

        $state->data['chips'][$player->id] -= $amount;
        $state->data['bets'][$player->id] = $amount;
        $state->logGameEvent('log.blackjack.bet', ['%player%' => $player->nickname, '%amount%' => $amount]);

        foreach ($this->playersInBettingOrder($state) as $index => $candidate) {
            if (null === $state->data['bets'][$candidate->id]) {
                $state->currentTurnIndex = $index;

                return;
            }
        }
        $this->deal($state);
    }

    private function hit(GameState $state): void
    {
        $this->assertPlaying($state);
        $player = $state->currentPlayer();
        $hand = &$state->data['hands'][$player->id];
        $hand[] = array_pop($state->data['deck']);

        $value = $this->rules->value($hand);
        $state->logGameEvent('log.blackjack.hit', ['%player%' => $player->nickname, '%value%' => $value]);

        if ($this->rules->isBust($hand)) {
            $state->logGameEvent('log.blackjack.bust', ['%player%' => $player->nickname]);
            $this->markDone($state);
        } elseif (21 === $value) {
            $this->markDone($state);
        }
    }

    private function stand(GameState $state): void
    {
        $this->assertPlaying($state);
        $state->logGameEvent('log.blackjack.stand', [
            '%player%' => $state->currentPlayer()->nickname,
            '%value%' => $this->rules->value($state->data['hands'][$state->currentPlayer()->id]),
        ]);
        $this->markDone($state);
    }

    private function double(GameState $state): void
    {
        $this->assertPlaying($state);
        $player = $state->currentPlayer();
        $bet = $state->data['bets'][$player->id];
        $hand = &$state->data['hands'][$player->id];

        if (2 !== \count($hand) || $state->data['chips'][$player->id] < $bet) {
            throw new InvalidMoveException('error.blackjack.cannot_double', domain: 'blackjack');
        }

        $state->data['chips'][$player->id] -= $bet;
        $state->data['bets'][$player->id] = $bet * 2;
        $hand[] = array_pop($state->data['deck']);

        $value = $this->rules->value($hand);
        $state->logGameEvent('log.blackjack.double', ['%player%' => $player->nickname, '%value%' => $value]);
        if ($this->rules->isBust($hand)) {
            $state->logGameEvent('log.blackjack.bust', ['%player%' => $player->nickname]);
        }
        $this->markDone($state);
    }

    private function assertPlaying(GameState $state): void
    {
        if ('playing' !== $state->data['phase']) {
            throw new InvalidMoveException('error.blackjack.bet_first', domain: 'blackjack');
        }
    }

    private function markDone(GameState $state): void
    {
        $state->data['done'][$state->currentPlayer()->id] = true;
        $this->advanceOrSettle($state);
    }

    private function advanceOrSettle(GameState $state): void
    {
        foreach ($this->playersInBettingOrder($state) as $index => $player) {
            if (null !== $state->data['bets'][$player->id] && !$state->data['done'][$player->id]) {
                $state->currentTurnIndex = $index;

                return;
            }
        }
        $this->settle($state);
    }

    private function deal(GameState $state): void
    {
        $state->data['phase'] = 'playing';
        foreach ($state->players as $player) {
            if (null === $state->data['bets'][$player->id]) {
                continue;
            }
            $hand = [array_pop($state->data['deck']), array_pop($state->data['deck'])];
            $state->data['hands'][$player->id] = $hand;
            $state->data['done'][$player->id] = $this->rules->isBlackjack($hand);
        }
        $state->data['dealer'] = [array_pop($state->data['deck']), array_pop($state->data['deck'])];
        $state->logGameEvent('log.blackjack.dealt', ['%upcard%' => $this->rules->value([$state->data['dealer'][0]])]);

        $this->advanceOrSettle($state);
    }

    private function settle(GameState $state): void
    {
        $dealer = &$state->data['dealer'];
        while ($this->rules->value($dealer) < GameRules::DEALER_STANDS_AT) {
            $dealer[] = array_pop($state->data['deck']);
        }
        $dealerValue = $this->rules->value($dealer);
        $dealerBust = $dealerValue > 21;
        $dealerBlackjack = $this->rules->isBlackjack($dealer);
        $state->logGameEvent('log.blackjack.dealer', ['%value%' => $dealerValue]);

        foreach ($state->players as $player) {
            $bet = $state->data['bets'][$player->id];
            if (null === $bet) {
                continue;
            }
            $hand = $state->data['hands'][$player->id];
            $value = $this->rules->value($hand);

            if ($this->rules->isBust($hand)) {
                $key = 'log.blackjack.lost';
                $payout = 0;
            } elseif ($this->rules->isBlackjack($hand)) {
                $payout = $dealerBlackjack ? $bet : $bet + (int) floor($bet * 1.5);
                $key = $dealerBlackjack ? 'log.blackjack.push' : 'log.blackjack.blackjack';
            } elseif ($dealerBust || $value > $dealerValue) {
                $payout = $bet * 2;
                $key = 'log.blackjack.won_round';
            } elseif ($value === $dealerValue) {
                $payout = $bet;
                $key = 'log.blackjack.push';
            } else {
                $payout = 0;
                $key = 'log.blackjack.lost';
            }

            $state->data['chips'][$player->id] += $payout;
            $state->logGameEvent($key, [
                '%player%' => $player->nickname,
                '%chips%' => $state->data['chips'][$player->id],
            ]);
        }

        $anyoneCanBet = false;
        foreach ($state->players as $player) {
            if ($state->data['chips'][$player->id] >= GameRules::MIN_BET) {
                $anyoneCanBet = true;
                break;
            }
        }

        if ($state->data['round'] >= self::ROUNDS || !$anyoneCanBet) {
            $this->finishGame($state);

            return;
        }

        $this->startRound($state);
    }

    private function startRound(GameState $state): void
    {
        ++$state->data['round'];
        $state->data['phase'] = 'betting';
        $state->data['deck'] = DeckFactory::deck52();
        $state->data['dealer'] = [];
        foreach ($state->players as $player) {
            $state->data['bets'][$player->id] = null;
            $state->data['hands'][$player->id] = [];
            $state->data['done'][$player->id] = false;
        }

        foreach ($this->playersInBettingOrder($state) as $index => $player) {
            if ($state->data['chips'][$player->id] >= GameRules::MIN_BET) {
                $state->currentTurnIndex = $index;
                break;
            }
        }
        foreach ($state->players as $player) {
            if ($state->data['chips'][$player->id] < GameRules::MIN_BET) {
                $state->data['bets'][$player->id] = null;
                $state->data['done'][$player->id] = true;
            }
        }

        $state->logGameEvent('log.blackjack.round_started', [
            '%round%' => $state->data['round'],
            '%total%' => self::ROUNDS,
        ]);
    }

    private function finishGame(GameState $state): void
    {
        $best = null;
        $bestChips = -1;
        $tie = false;
        $summary = [];
        foreach ($state->players as $player) {
            $chips = $state->data['chips'][$player->id];
            $summary[] = sprintf('%s: %d', $player->nickname, $chips);
            if ($chips > $bestChips) {
                $bestChips = $chips;
                $best = $player;
                $tie = false;
            } elseif ($chips === $bestChips) {
                $tie = true;
            }
        }

        $state->finish($tie ? null : $best?->id);
        $state->logGameEvent('log.blackjack.final', ['%chips%' => implode(', ', $summary)]);
        if (!$tie && null !== $best) {
            $state->logGameEvent('log.blackjack.won', ['%player%' => $best->nickname, '%chips%' => $bestChips]);
        }
    }

    /**
     * Players keyed by their seat index, so the caller can set currentTurnIndex directly.
     *
     * @return array<int, Player>
     */
    private function playersInBettingOrder(GameState $state): array
    {
        return array_filter($state->players, function ($player) use ($state) {
            return $state->data['chips'][$player->id] >= GameRules::MIN_BET
                || null !== $state->data['bets'][$player->id];
        });
    }
}
