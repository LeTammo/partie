<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Card\Rank;
use App\Game\Core\Card\Suit;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Games\Poker\GameDefinition;
use App\Game\Games\Poker\GameRenderer;
use App\Game\Games\Poker\GameRules;
use App\Game\Games\Poker\HandEvaluator;

final class PokerTest extends GameTestCase
{
    private GameRules $rules;
    private HandEvaluator $evaluator;
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->evaluator = new HandEvaluator();
        $this->game = new GameDefinition($this->rules, $this->evaluator, new GameRenderer($this->rules));
    }

    private function state(int $players = 2, array $settings = []): GameState
    {
        return $this->game->createInitialState(self::players($players), $settings + ['startChips' => 200, 'smallBlind' => 5]);
    }

    // ---------- HandEvaluator ----------

    public function testStraightFlushBeatsQuads(): void
    {
        $straightFlush = $this->evaluator->best([
            self::card(Suit::Hearts, Rank::Five),
            self::card(Suit::Hearts, Rank::Six),
            self::card(Suit::Hearts, Rank::Seven),
            self::card(Suit::Hearts, Rank::Eight),
            self::card(Suit::Hearts, Rank::Nine),
            self::card(Suit::Clubs, Rank::Two),
            self::card(Suit::Spades, Rank::Three),
        ]);
        $quads = $this->evaluator->best([
            self::card(Suit::Hearts, Rank::King),
            self::card(Suit::Clubs, Rank::King),
            self::card(Suit::Diamonds, Rank::King),
            self::card(Suit::Spades, Rank::King),
            self::card(Suit::Hearts, Rank::Two),
            self::card(Suit::Clubs, Rank::Three),
            self::card(Suit::Spades, Rank::Four),
        ]);

        self::assertSame(8, $straightFlush[0]);
        self::assertSame(7, $quads[0]);
        self::assertGreaterThan(0, $this->evaluator->compare($straightFlush, $quads));
    }

    public function testWheelStraightRanksBelowSixHighStraight(): void
    {
        $wheel = $this->evaluator->best([
            self::card(Suit::Hearts, Rank::Ace),
            self::card(Suit::Clubs, Rank::Two),
            self::card(Suit::Diamonds, Rank::Three),
            self::card(Suit::Spades, Rank::Four),
            self::card(Suit::Hearts, Rank::Five),
            self::card(Suit::Clubs, Rank::Nine),
            self::card(Suit::Spades, Rank::King),
        ]);
        $sixHigh = $this->evaluator->best([
            self::card(Suit::Hearts, Rank::Two),
            self::card(Suit::Clubs, Rank::Three),
            self::card(Suit::Diamonds, Rank::Four),
            self::card(Suit::Spades, Rank::Five),
            self::card(Suit::Hearts, Rank::Six),
            self::card(Suit::Clubs, Rank::Nine),
            self::card(Suit::Spades, Rank::King),
        ]);

        self::assertSame([4, 5], \array_slice($wheel, 0, 2));
        self::assertSame([4, 6], \array_slice($sixHigh, 0, 2));
        self::assertGreaterThan(0, $this->evaluator->compare($sixHigh, $wheel));
    }

    public function testFullHouseBeatsFlush(): void
    {
        $fullHouse = $this->evaluator->best([
            self::card(Suit::Hearts, Rank::Nine),
            self::card(Suit::Clubs, Rank::Nine),
            self::card(Suit::Diamonds, Rank::Nine),
            self::card(Suit::Spades, Rank::Four),
            self::card(Suit::Hearts, Rank::Four),
            self::card(Suit::Clubs, Rank::Two),
            self::card(Suit::Spades, Rank::Three),
        ]);
        $flush = $this->evaluator->best([
            self::card(Suit::Hearts, Rank::Two),
            self::card(Suit::Hearts, Rank::Five),
            self::card(Suit::Hearts, Rank::Eight),
            self::card(Suit::Hearts, Rank::Jack),
            self::card(Suit::Hearts, Rank::King),
            self::card(Suit::Clubs, Rank::Two),
            self::card(Suit::Spades, Rank::Three),
        ]);

        self::assertGreaterThan(0, $this->evaluator->compare($fullHouse, $flush));
    }

    // ---------- side pots ----------

    public function testSidePotsLayerByContribution(): void
    {
        $pots = $this->rules->sidePots(
            ['p0' => 20, 'p1' => 50, 'p2' => 50],
            ['p0' => false, 'p1' => false, 'p2' => false],
        );

        self::assertCount(2, $pots);
        self::assertSame(60, $pots[0]['amount']); // 20 * 3 players
        self::assertSame(['p0', 'p1', 'p2'], $pots[0]['eligible']);
        self::assertSame(60, $pots[1]['amount']); // (50-20) * 2 players
        self::assertSame(['p1', 'p2'], $pots[1]['eligible']);
    }

    public function testFoldedPlayersContributeButAreNotEligible(): void
    {
        $pots = $this->rules->sidePots(
            ['p0' => 30, 'p1' => 30],
            ['p0' => true, 'p1' => false],
        );

        self::assertCount(1, $pots);
        self::assertSame(60, $pots[0]['amount']);
        self::assertSame(['p1'], $pots[0]['eligible']);
    }

    // ---------- game flow ----------

    public function testInitialStatePostsBlindsAndDealsTwoCardsEach(): void
    {
        $state = $this->state();

        self::assertSame('preflop', $state->data['phase']);
        self::assertCount(2, $state->table->hand('p0')->items);
        self::assertCount(2, $state->table->hand('p1')->items);
        // heads-up: dealer (p0) is small blind, p1 is big blind
        self::assertSame(195, $state->data['chips']['p0']);
        self::assertSame(190, $state->data['chips']['p1']);
        self::assertSame(10, $state->data['currentBet']);
        self::assertSame('p0', $state->currentPlayer()->id);
    }

    public function testFoldEndsHandUncontestedAndAwardsPot(): void
    {
        $state = $this->state();
        $potBefore = array_sum($state->data['contributed']);

        $this->game->applyMove($state, 'p0', ['action' => 'fold']);
        self::assertSame('showdown', $state->data['phase']);

        $this->game->applyAutoStep($state); // showdown -> uncontested award
        self::assertSame('handend', $state->data['phase']);
        self::assertSame(190 + $potBefore, $state->data['chips']['p1']);

        $this->game->applyMove($state, 'p1', ['action' => 'next_round']); // handend -> next hand (both still have chips)
        self::assertSame('preflop', $state->data['phase']);
        self::assertSame(2, $state->data['handNumber']);
    }

    public function testCannotActOutOfTurn(): void
    {
        $state = $this->state();

        try {
            $this->game->applyMove($state, 'p1', ['action' => 'fold']);
            self::fail('expected InvalidMoveException - it is p0 (the dealer/small blind) to act first heads-up');
        } catch (InvalidMoveException) {
            self::assertFalse($state->data['folded']['p1']);
        }
    }

    public function testRaiseReopensActionForOtherPlayers(): void
    {
        $state = $this->state(3);
        // seat0 = p0 (dealer), seat1 = p1 (small blind), seat2 = p2 (big blind); p0 acts first preflop
        $this->game->applyMove($state, 'p0', ['action' => 'call']);
        $this->game->applyMove($state, 'p1', ['action' => 'raise', 'to' => 30]);

        // turn passes to p2 next, but p0 already acted this round and must act again after the raise
        self::assertSame('p2', $state->currentPlayer()->id);
        self::assertFalse($state->data['actedThisRound']['p0']);
        self::assertTrue($state->data['actedThisRound']['p1']);
    }

    public function testBettingRoundCompletesAndDealsFlop(): void
    {
        $state = $this->state();
        // p0 (SB/dealer) calls to match the big blind, p1 (BB) checks - preflop closes
        $this->game->applyMove($state, 'p0', ['action' => 'call']);
        $this->game->applyMove($state, 'p1', ['action' => 'call']);

        self::assertSame('dealflop', $state->data['phase']);
        $this->game->applyAutoStep($state);

        self::assertSame('flop', $state->data['phase']);
        self::assertCount(3, $state->table->zone('community')->items);
        self::assertSame(0, $state->data['currentBet']);
        // heads-up postflop: non-dealer (p1) acts first
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testAllInForLessThanCurrentBetIsTreatedAsACall(): void
    {
        $state = $this->state();
        $state->data['chips']['p0'] = 3; // far less than the 10-chip big blind to call

        $this->game->applyMove($state, 'p0', ['action' => 'allin']);

        self::assertSame(0, $state->data['chips']['p0']);
        self::assertTrue($state->data['allIn']['p0']);
        self::assertSame(8, $state->data['bets']['p0']); // 5 (blind already posted) + 3 all-in
    }

    public function testFullHandRunsToShowdownAndAwardsTheBetterHand(): void
    {
        $state = $this->state();
        $state->table->hand('p0')->items = [self::card(Suit::Spades, Rank::Ace), self::card(Suit::Spades, Rank::King)];
        $state->table->hand('p1')->items = [self::card(Suit::Clubs, Rank::Two), self::card(Suit::Diamonds, Rank::Seven)];
        $state->table->zone('stock')->items = array_reverse([
            self::card(Suit::Spades, Rank::Queen), self::card(Suit::Spades, Rank::Jack), self::card(Suit::Spades, Rank::Ten), // flop: p0 has a royal flush
            self::card(Suit::Hearts, Rank::Two),  // turn
            self::card(Suit::Hearts, Rank::Three), // river
        ]);

        $this->game->applyMove($state, 'p0', ['action' => 'call']);
        $this->game->applyMove($state, 'p1', ['action' => 'call']);
        $this->game->applyAutoStep($state); // deal flop

        $this->game->applyMove($state, 'p1', ['action' => 'call']);
        $this->game->applyMove($state, 'p0', ['action' => 'call']);
        $this->game->applyAutoStep($state); // deal turn

        $this->game->applyMove($state, 'p1', ['action' => 'call']);
        $this->game->applyMove($state, 'p0', ['action' => 'call']);
        $this->game->applyAutoStep($state); // deal river

        $this->game->applyMove($state, 'p1', ['action' => 'call']);
        $this->game->applyMove($state, 'p0', ['action' => 'call']);
        self::assertSame('showdown', $state->data['phase']);

        $this->game->applyAutoStep($state); // showdown

        self::assertSame(210, $state->data['chips']['p0']);
        self::assertSame(190, $state->data['chips']['p1']);
    }

    public function testPreflopFastRaiseButtonsAreBigBlindMultiples(): void
    {
        $state = $this->state();
        // heads-up: big blind is 10, p0 (dealer/SB) acts first preflop

        $view = $this->game->buildView($state, 'p0');

        self::assertSame(
            [20, 25, 30],
            $view['betOptions']
        );
    }

    public function testPostflopFastRaiseButtonsArePotFractions(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'call']);
        $this->game->applyMove($state, 'p1', ['action' => 'call']);
        $this->game->applyAutoStep($state); // deal flop, pot is now 20, currentBet resets to 0

        $view = $this->game->buildView($state, 'p1');

        self::assertSame(
            [10, 15, 20],
            $view['betOptions']
        );
    }

    public function testHandEndWaitsForNextRoundInsteadOfAutoAdvancing(): void
    {
        $state = $this->state();

        $this->game->applyMove($state, 'p0', ['action' => 'fold']);
        $this->game->applyAutoStep($state); // showdown -> uncontested award
        self::assertSame('handend', $state->data['phase']);

        self::assertFalse($this->game->hasAutoStep($state));

        try {
            $this->game->applyMove($state, 'p1', ['action' => 'call']);
            self::fail('no betting action should be accepted while waiting on handend');
        } catch (InvalidMoveException) {
            self::assertSame('handend', $state->data['phase']);
        }
    }

    public function testGameFinishesWhenOnlyOnePlayerHasChipsLeft(): void
    {
        $state = $this->state();
        $state->data['chips']['p1'] = 0;
        $state->data['phase'] = 'handend';

        $this->game->applyMove($state, 'p0', ['action' => 'next_round']);

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
    }
}
