<?php

declare(strict_types=1);

namespace App\Game\Games\Poker;

use App\Game\Core\Card\DeckFactory;
use App\Game\Core\Card\PlayingCard;
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
    private const int START_CHIPS = 200;
    private const int SMALL_BLIND = 5;
    private const array BETTING_PHASES = ['preflop', 'flop', 'turn', 'river'];
    private const array AUTO_PHASES = ['dealflop', 'dealturn', 'dealriver', 'showdown'];

    public function __construct(
        private GameRules      $rules,
        private HandEvaluator  $evaluator,
        private GameRenderer   $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'poker';
    }

    public function getName(): string
    {
        return 'game.poker.name';
    }

    public function getDescription(): string
    {
        return 'game.poker.description';
    }

    public function getIcon(): string
    {
        return 'poker';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 6;
    }

    public function settings(): array
    {
        return [
            new GameSetting(key: 'startChips', labelKey: 'setting.poker.start_chips', type: GameSettingType::Int, default: self::START_CHIPS, min: 20, max: 2000),
            new GameSetting(key: 'smallBlind', labelKey: 'setting.poker.small_blind', type: GameSettingType::Int, default: self::SMALL_BLIND, min: 1, max: 100),
        ];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;
        $state->table = new Table();
        $state->table->add(new Zone('community'));
        $state->table->add(new Zone('stock', visibility: ZoneVisibility::Hidden));
        foreach ($players as $player) {
            $state->table->add(new Zone('hand:'.$player->id, $player->id, ZoneVisibility::Owner));
        }

        $startChips = (int) ($settings['startChips'] ?? self::START_CHIPS);
        foreach ($players as $player) {
            $state->data['chips'][$player->id] = $startChips;
        }
        $state->data['dealerSeat'] = -1;
        $state->data['handNumber'] = 0;
        $state->data['pot'] = 0;
        $state->data['lastResult'] = [];
        $state->data['autoStep'] = 0;

        $this->startHand($state);

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        $action = $payload['action'] ?? '';

        if ('next_round' === $action) {
            $this->nextRound($state, $playerId);

            return;
        }

        if (!\in_array($state->data['phase'], self::BETTING_PHASES, true) || !$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($action) {
            'fold' => $this->fold($state),
            'call' => $this->call($state),
            'raise' => $this->raiseTo($state, $this->intParam($payload, 'to')),
            'allin' => $this->allIn($state),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/poker/table.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    public function hasAutoStep(GameState $state): bool
    {
        return GameStatus::Running === $state->status && \in_array($state->data['phase'], self::AUTO_PHASES, true);
    }

    public function applyAutoStep(GameState $state): void
    {
        $state->data['autoStep'] = ($state->data['autoStep'] ?? 0) + 1;

        match ($state->data['phase']) {
            'dealflop' => $this->dealStreet($state, 'flop', 3),
            'dealturn' => $this->dealStreet($state, 'turn', 1),
            'dealriver' => $this->dealStreet($state, 'river', 1),
            'showdown' => $this->showdown($state),
            default => null,
        };
    }

    // ---------- hand lifecycle ----------

    /**
     * Manually triggered once players have seen the showdown and are ready
     * to continue, rather than auto-advancing straight past it.
     */
    private function nextRound(GameState $state, string $playerId): void
    {
        if ('handend' !== $state->data['phase'] || null === $state->playerById($playerId)) {
            $this->invalidMove('error.poker.not_hand_end');
        }

        $this->handEnd($state);
    }

    private function startHand(GameState $state): void
    {
        ++$state->data['handNumber'];
        $active = $this->activePlayers($state);

        $state->data['dealerSeat'] = $this->nextActiveSeat($state, $state->data['dealerSeat'], $active);

        $state->data['folded'] = [];
        $state->data['allIn'] = [];
        $state->data['bets'] = [];
        $state->data['contributed'] = [];
        $state->data['actedThisRound'] = [];
        $state->data['dealtIn'] = [];
        foreach ($state->players as $player) {
            $isActive = \in_array($player, $active, true);
            $state->data['folded'][$player->id] = !$isActive;
            $state->data['allIn'][$player->id] = false;
            $state->data['bets'][$player->id] = 0;
            $state->data['contributed'][$player->id] = 0;
            $state->data['actedThisRound'][$player->id] = false;
            $state->data['dealtIn'][$player->id] = $isActive;
            $state->table->hand($player->id)->items = [];
        }

        $deck = DeckFactory::deck52();
        foreach ($active as $player) {
            $state->table->hand($player->id)->items = array_splice($deck, 0, 2);
        }
        $state->table->zone('community')->items = [];
        $state->table->zone('stock')->items = $deck;

        $smallBlind = (int) ($this->setting($state, 'smallBlind') ?? self::SMALL_BLIND);
        $bigBlind = $smallBlind * 2;

        if (2 === \count($active)) {
            $sb = array_find($active, static fn (Player $p): bool => $p->seat === $state->data['dealerSeat']);
            $bb = $sb === $active[0] ? $active[1] : $active[0];
        } else {
            $sb = $active[$this->indexAfterSeat($active, $state->data['dealerSeat'])];
            $bb = $active[$this->indexAfterSeat($active, $sb->seat)];
        }

        $this->postBlind($state, $sb, $smallBlind);
        $this->postBlind($state, $bb, $bigBlind);
        $state->data['currentBet'] = $state->data['bets'][$bb->id];

        $state->data['phase'] = 'preflop';
        $firstToAct = $active[$this->indexAfterSeat($active, $bb->seat)];
        $state->currentTurnIndex = $firstToAct->seat;

        $state->logGameEvent('log.poker.hand_started', ['%hand%' => $state->data['handNumber'], '%sb%' => $sb->nickname, '%bb%' => $bb->nickname]);

        $this->openBettingRound($state);
    }

    /**
     * @return list<Player>
     */
    private function activePlayers(GameState $state): array
    {
        return array_values(array_filter($state->players, fn (Player $p): bool => $state->data['chips'][$p->id] > 0));
    }

    /**
     * @param list<Player> $active
     */
    private function nextActiveSeat(GameState $state, int $afterSeat, array $active): int
    {
        $seats = array_map(static fn (Player $p): int => $p->seat, $active);
        sort($seats);
        foreach ($seats as $seat) {
            if ($seat > $afterSeat) {
                return $seat;
            }
        }

        return $seats[0];
    }

    /**
     * @param list<Player> $active
     */
    private function indexAfterSeat(array $active, int $afterSeat): int
    {
        $seats = array_map(static fn (Player $p): int => $p->seat, $active);
        foreach ($seats as $i => $seat) {
            if ($seat > $afterSeat) {
                return $i;
            }
        }

        return 0;
    }

    private function postBlind(GameState $state, Player $player, int $amount): void
    {
        $amount = min($amount, $state->data['chips'][$player->id]);
        $state->data['chips'][$player->id] -= $amount;
        $state->data['bets'][$player->id] += $amount;
        $state->data['contributed'][$player->id] += $amount;
        if (0 === $state->data['chips'][$player->id]) {
            $state->data['allIn'][$player->id] = true;
        }
    }

    // ---------- player actions ----------

    private function fold(GameState $state): void
    {
        $player = $state->currentPlayer();
        $state->data['folded'][$player->id] = true;
        $state->data['actedThisRound'][$player->id] = true;
        $state->logGameEvent('log.poker.folded', ['%player%' => $player->nickname]);
        $this->afterAction($state);
    }

    private function call(GameState $state): void
    {
        $player = $state->currentPlayer();
        $amount = min($state->data['currentBet'] - $state->data['bets'][$player->id], $state->data['chips'][$player->id]);
        $state->data['chips'][$player->id] -= $amount;
        $state->data['bets'][$player->id] += $amount;
        $state->data['contributed'][$player->id] += $amount;
        if (0 === $state->data['chips'][$player->id]) {
            $state->data['allIn'][$player->id] = true;
        }
        $state->data['actedThisRound'][$player->id] = true;

        $state->logGameEvent($amount > 0 ? 'log.poker.called' : 'log.poker.checked', ['%player%' => $player->nickname, '%amount%' => $amount]);
        $this->afterAction($state);
    }

    private function raiseTo(GameState $state, int $to): void
    {
        $player = $state->currentPlayer();
        $stackCap = $state->data['chips'][$player->id] + $state->data['bets'][$player->id];
        $to = min($to, $stackCap);

        if ($to <= $state->data['currentBet'] || $to <= $state->data['bets'][$player->id]) {
            $this->invalidMove('error.poker.invalid_raise');
        }

        $amount = $to - $state->data['bets'][$player->id];
        $state->data['chips'][$player->id] -= $amount;
        $state->data['bets'][$player->id] = $to;
        $state->data['contributed'][$player->id] += $amount;
        if (0 === $state->data['chips'][$player->id]) {
            $state->data['allIn'][$player->id] = true;
        }
        $state->data['currentBet'] = $to;

        foreach ($state->players as $p) {
            if ($p->id !== $player->id && !$state->data['folded'][$p->id] && !$state->data['allIn'][$p->id]) {
                $state->data['actedThisRound'][$p->id] = false;
            }
        }
        $state->data['actedThisRound'][$player->id] = true;

        $state->logGameEvent('log.poker.raised', ['%player%' => $player->nickname, '%amount%' => $to]);
        $this->afterAction($state);
    }

    private function allIn(GameState $state): void
    {
        $player = $state->currentPlayer();
        $to = $state->data['chips'][$player->id] + $state->data['bets'][$player->id];

        if ($to > $state->data['currentBet']) {
            $this->raiseTo($state, $to);
        } else {
            // shoving for less than the current bet is just an all-in call
            $this->call($state);
        }
    }

    // ---------- betting round resolution ----------

    /**
     * Called after a player's own action: move on to whoever acts next.
     */
    private function afterAction(GameState $state): void
    {
        if ($this->resolveRoundIfSettled($state)) {
            return;
        }

        $this->advanceTurn($state);
    }

    /**
     * Called when a betting round has just opened (hand/street start) and
     * `currentTurnIndex` already points at the correct first actor - only
     * close the round early if it's already trivially settled (e.g.
     * everyone still in is all-in), never advance the turn ourselves.
     */
    private function openBettingRound(GameState $state): void
    {
        $this->resolveRoundIfSettled($state);
    }

    /**
     * @return bool true if the round is over (uncontested or fully matched) and the phase advanced
     */
    private function resolveRoundIfSettled(GameState $state): bool
    {
        $remaining = array_filter($state->players, fn (Player $p): bool => ($state->data['dealtIn'][$p->id] ?? false) && !$state->data['folded'][$p->id]);

        if (\count($remaining) <= 1) {
            $state->data['phase'] = 'showdown';

            return true;
        }

        if (!$this->bettingRoundComplete($state)) {
            return false;
        }

        $state->data['phase'] = match ($state->data['phase']) {
            'preflop' => 'dealflop',
            'flop' => 'dealturn',
            'turn' => 'dealriver',
            'river' => 'showdown',
            default => $state->data['phase'],
        };

        return true;
    }

    private function bettingRoundComplete(GameState $state): bool
    {
        foreach ($state->players as $player) {
            if (!($state->data['dealtIn'][$player->id] ?? false) || $state->data['folded'][$player->id] || $state->data['allIn'][$player->id]) {
                continue;
            }
            if (!$state->data['actedThisRound'][$player->id] || $state->data['bets'][$player->id] !== $state->data['currentBet']) {
                return false;
            }
        }

        return true;
    }

    private function advanceTurn(GameState $state): void
    {
        $count = \count($state->players);
        $seat = $state->currentTurnIndex;
        for ($i = 0; $i < $count; ++$i) {
            $seat = ($seat + 1) % $count;
            $player = $state->players[$seat];
            if (($state->data['dealtIn'][$player->id] ?? false) && !$state->data['folded'][$player->id] && !$state->data['allIn'][$player->id]) {
                $state->currentTurnIndex = $seat;

                return;
            }
        }
    }

    // ---------- auto steps ----------

    private function dealStreet(GameState $state, string $phase, int $count): void
    {
        $stock = $state->table->zone('stock');
        $community = $state->table->zone('community');
        for ($i = 0; $i < $count && !$stock->isEmpty(); ++$i) {
            $community->push($stock->pop());
        }

        $state->data['phase'] = $phase;
        foreach ($state->players as $player) {
            if (($state->data['dealtIn'][$player->id] ?? false) && !$state->data['folded'][$player->id]) {
                $state->data['bets'][$player->id] = 0;
                $state->data['actedThisRound'][$player->id] = false;
            }
        }
        $state->data['currentBet'] = 0;

        $active = array_values(array_filter($state->players, fn (Player $p): bool => ($state->data['dealtIn'][$p->id] ?? false) && !$state->data['folded'][$p->id]));
        if ([] !== $active) {
            $state->currentTurnIndex = $active[$this->indexAfterSeat($active, $state->data['dealerSeat'])]->seat;
        }

        $state->logGameEvent('log.poker.'.$phase, ['%cards%' => \count($community->items)]);
        $this->openBettingRound($state);
    }

    private function showdown(GameState $state): void
    {
        $active = array_values(array_filter($state->players, fn (Player $p): bool => ($state->data['dealtIn'][$p->id] ?? false) && !$state->data['folded'][$p->id]));
        $pot = array_sum($state->data['contributed']);
        $results = [];

        if (1 === \count($active)) {
            $winner = $active[0];
            $state->data['chips'][$winner->id] += $pot;
            $results[] = ['playerId' => $winner->id, 'amount' => $pot, 'hand' => null];
            $state->logGameEvent('log.poker.uncontested', ['%player%' => $winner->nickname, '%amount%' => $pot]);
        } else {
            $community = $state->table->zone('community')->items;
            $ranks = [];
            foreach ($active as $player) {
                /** @var list<PlayingCard> $cards */
                $cards = [...$state->table->hand($player->id)->items, ...$community];
                $ranks[$player->id] = $this->evaluator->best($cards);
            }

            foreach ($this->rules->sidePots($state->data['contributed'], $state->data['folded']) as $sidePot) {
                $eligible = $sidePot['eligible'];
                $best = null;
                foreach ($eligible as $playerId) {
                    if (null === $best || $this->evaluator->compare($ranks[$playerId], $ranks[$best[0]]) > 0) {
                        $best = [$playerId];
                    } elseif ($this->evaluator->compare($ranks[$playerId], $ranks[$best[0]]) === 0) {
                        $best[] = $playerId;
                    }
                }
                $winners = $best ?? [];
                $share = \intdiv($sidePot['amount'], \count($winners));
                $remainder = $sidePot['amount'] % \count($winners);
                foreach ($winners as $i => $playerId) {
                    $amount = $share + (0 === $i ? $remainder : 0);
                    $state->data['chips'][$playerId] += $amount;
                    $results[] = ['playerId' => $playerId, 'amount' => $amount, 'hand' => $ranks[$playerId][0]];
                }
            }
            $state->logGameEvent('log.poker.showdown');
        }

        $state->data['lastResult'] = $results;
        $state->data['phase'] = 'handend';
    }

    private function handEnd(GameState $state): void
    {
        foreach ($state->players as $player) {
            if (0 === $state->data['chips'][$player->id]) {
                $state->logGameEvent('log.poker.busted', ['%player%' => $player->nickname]);
            }
        }

        $stillIn = $this->activePlayers($state);
        if (\count($stillIn) <= 1) {
            $winner = $stillIn[0] ?? null;
            $state->finish($winner?->id);
            if (null !== $winner) {
                $state->logEvent('log.won', ['%player%' => $winner->nickname]);
            }

            return;
        }

        $this->startHand($state);
    }
}
