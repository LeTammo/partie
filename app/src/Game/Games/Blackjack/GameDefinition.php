<?php

declare(strict_types=1);

namespace App\Game\Games\Blackjack;

use App\Game\Core\Card\DeckFactory;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameSetting;
use App\Game\Core\Model\GameSettingType;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Player;
use App\Game\Core\Service\AbstractGameDefinition;
use App\Game\Core\Service\AutoPlayingEngineInterface;
use App\Game\Core\Zone\Table;
use App\Game\Core\Zone\Zone;
use App\Game\Core\Zone\ZoneVisibility;

final readonly class GameDefinition extends AbstractGameDefinition implements AutoPlayingEngineInterface
{
    private const int START_CHIPS = 100;
    private const int ROUNDS = 5;

    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
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

    public function settings(): array
    {
        return [
            new GameSetting(
                key: 'startChips',
                labelKey: 'setting.blackjack.start_chips',
                type: GameSettingType::Int,
                default: self::START_CHIPS,
                min: 20,
                max: 1000,
            ),
            new GameSetting(
                key: 'dealerStandsAt',
                labelKey: 'setting.blackjack.dealer_stands_at',
                type: GameSettingType::Int,
                default: GameRules::DEALER_STANDS_AT,
                min: 15,
                max: 19,
            ),
        ];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;
        $table = $state->table = new Table();

        $startChips = (int) ($settings['startChips'] ?? self::START_CHIPS);
        foreach ($players as $player) {
            $table->add(new Zone('hand:'.$player->id, $player->id)); // blackjack hands are public
            $state->data['chips'][$player->id] = $startChips;
        }
        $table->add(new Zone('dealer'));
        $table->add(new Zone('stock', visibility: ZoneVisibility::Hidden));
        $state->data['round'] = 0;
        $state->data['roundsTotal'] = self::ROUNDS;
        $state->data['autoStep'] = 0;

        $this->startRound($state);

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'bet' => $this->bet($state, $this->intParam($payload, 'amount', 0)),
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
            $this->invalidMove('error.blackjack.no_betting');
        }

        $player = $state->currentPlayer();
        if (!\in_array($amount, GameRules::BET_OPTIONS, true)
            || $amount > $state->data['chips'][$player->id]) {
            $this->invalidMove('error.blackjack.invalid_bet');
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
        $hand = $state->table->hand($player->id);
        $hand->push($state->table->zone('stock')->pop());

        $value = $this->rules->value($hand->items);
        $state->logGameEvent('log.blackjack.hit', ['%player%' => $player->nickname, '%value%' => $value]);

        if ($this->rules->isBust($hand->items)) {
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
            '%value%' => $this->rules->value($state->table->hand($state->currentPlayer()->id)->items),
        ]);
        $this->markDone($state);
    }

    private function double(GameState $state): void
    {
        $this->assertPlaying($state);
        $player = $state->currentPlayer();
        $bet = $state->data['bets'][$player->id];
        $hand = $state->table->hand($player->id);

        if (2 !== $hand->count() || $state->data['chips'][$player->id] < $bet) {
            $this->invalidMove('error.blackjack.cannot_double');
        }

        $state->data['chips'][$player->id] -= $bet;
        $state->data['bets'][$player->id] = $bet * 2;
        $hand->push($state->table->zone('stock')->pop());

        $value = $this->rules->value($hand->items);
        $state->logGameEvent('log.blackjack.double', ['%player%' => $player->nickname, '%value%' => $value]);
        if ($this->rules->isBust($hand->items)) {
            $state->logGameEvent('log.blackjack.bust', ['%player%' => $player->nickname]);
        }
        $this->markDone($state);
    }

    private function assertPlaying(GameState $state): void
    {
        if ('playing' !== $state->data['phase']) {
            $this->invalidMove('error.blackjack.bet_first');
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

        $state->data['phase'] = 'dealer';
        $state->data['dealerRevealed'] = false;
        $state->data['settleIndex'] = 0;
    }

    public function hasAutoStep(GameState $state): bool
    {
        return GameStatus::Running === $state->status
            && \in_array($state->data['phase'], ['dealer', 'settle', 'roundend'], true);
    }

    public function applyAutoStep(GameState $state): void
    {
        $state->data['autoStep'] = ($state->data['autoStep'] ?? 0) + 1;

        match ($state->data['phase']) {
            'dealer' => $this->dealerStep($state),
            'settle' => $this->settleStep($state),
            'roundend' => $this->roundEndStep($state),
            default => null,
        };
    }

    private function dealerStep(GameState $state): void
    {
        $dealer = $state->table->zone('dealer');

        if (!$state->data['dealerRevealed']) {
            $state->data['dealerRevealed'] = true;
            $state->logGameEvent('log.blackjack.reveal', ['%value%' => $this->rules->value($dealer->items)]);

            return;
        }

        $dealerStandsAt = (int) ($this->setting($state, 'dealerStandsAt') ?? GameRules::DEALER_STANDS_AT);
        if ($this->rules->value($dealer->items) < $dealerStandsAt) {
            $dealer->push($state->table->zone('stock')->pop());
            $state->logGameEvent('log.blackjack.dealer_draw', ['%value%' => $this->rules->value($dealer->items)]);

            return;
        }

        $state->logGameEvent('log.blackjack.dealer', ['%value%' => $this->rules->value($dealer->items)]);
        $state->data['phase'] = 'settle';
    }

    private function settleStep(GameState $state): void
    {
        $index = $state->data['settleIndex'];
        while ($index < \count($state->players) && null === $state->data['bets'][$state->players[$index]->id]) {
            ++$index;
        }

        if ($index >= \count($state->players)) {
            $state->data['phase'] = 'roundend';

            return;
        }

        $this->settlePlayer($state, $state->players[$index]);
        $state->data['settleIndex'] = $index + 1;
    }

    private function roundEndStep(GameState $state): void
    {
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

    private function deal(GameState $state): void
    {
        $state->data['phase'] = 'playing';
        foreach ($state->players as $player) {
            if (null === $state->data['bets'][$player->id]) {
                continue;
            }
            $stock = $state->table->zone('stock');
            $hand = [$stock->pop(), $stock->pop()];
            $state->table->hand($player->id)->items = $hand;
            $state->data['done'][$player->id] = $this->rules->isBlackjack($hand);
        }
        $stock = $state->table->zone('stock');
        $state->table->zone('dealer')->items = [$stock->pop(), $stock->pop()];
        $state->logGameEvent('log.blackjack.dealt', ['%upcard%' => $this->rules->value([$state->table->zone('dealer')->items[0]])]);

        $this->advanceOrSettle($state);
    }

    private function settlePlayer(GameState $state, Player $player): void
    {
        $dealer = $state->table->zone('dealer')->items;
        $dealerValue = $this->rules->value($dealer);
        $dealerBust = $dealerValue > 21;
        $dealerBlackjack = $this->rules->isBlackjack($dealer);

        $bet = $state->data['bets'][$player->id];
        $hand = $state->table->hand($player->id)->items;
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

    private function startRound(GameState $state): void
    {
        ++$state->data['round'];
        $state->data['phase'] = 'betting';
        $state->data['dealerRevealed'] = false;
        $state->data['settleIndex'] = 0;
        $state->table->zone('stock')->items = DeckFactory::deck52();
        $state->table->zone('dealer')->items = [];
        foreach ($state->players as $player) {
            $state->data['bets'][$player->id] = null;
            $state->table->hand($player->id)->items = [];
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

    /** @return array<int, Player> */
    private function playersInBettingOrder(GameState $state): array
    {
        return array_filter($state->players, function ($player) use ($state) {
            return $state->data['chips'][$player->id] >= GameRules::MIN_BET
                || null !== $state->data['bets'][$player->id];
        });
    }
}
