<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Rank;
use App\Game\Core\Card\Suit;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Games\Rummy\GameDefinition;
use App\Game\Games\Rummy\GameRenderer;
use App\Game\Games\Rummy\GameRules;

final class RummyTest extends GameTestCase
{
    private GameRules $rules;
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->game = new GameDefinition($this->rules, new GameRenderer());
    }

    private function state(array $handP0): GameState
    {
        $state = $this->game->createInitialState(self::players(2));
        $state->table->hand('p0')->items = $handP0;
        $state->table->zone('stock')->items = [self::card(Suit::Clubs, Rank::Two), self::card(Suit::Diamonds, Rank::Three)];
        $state->table->zone('discard')->items = [self::card(Suit::Spades, Rank::Four)];

        return $state;
    }

    public function testValidateSet(): void
    {
        $set = [
            self::card(Suit::Hearts, Rank::King),
            self::card(Suit::Spades, Rank::King),
            self::card(Suit::Clubs, Rank::King),
        ];
        self::assertSame(['type' => 'set', 'points' => 30], $this->rules->validateMeld($set));

        // duplicate suit is not a set
        $bad = [$set[0], $set[0], $set[2]];
        self::assertNull($this->rules->validateMeld($bad));
    }

    public function testValidateRunWithJokerAndAceLow(): void
    {
        $run = [
            self::card(Suit::Hearts, Rank::Ace),
            self::card(Suit::Hearts, Rank::Two),
            self::card(Suit::Hearts, Rank::Three),
        ];
        $result = $this->rules->validateMeld($run);
        self::assertSame('run', $result['type']);
        self::assertSame(1 + 2 + 3, $result['points']);

        $withJoker = [
            self::card(Suit::Hearts, Rank::Ten),
            PlayingCard::jokerCard(),
            self::card(Suit::Hearts, Rank::Queen),
        ];
        $result = $this->rules->validateMeld($withJoker);
        self::assertSame('run', $result['type']);
        self::assertSame(30, $result['points']); // 10 + J(10) + Q(10)

        // mixed suits are not a run
        self::assertNull($this->rules->validateMeld([
            self::card(Suit::Hearts, Rank::Ten),
            self::card(Suit::Spades, Rank::Jack),
            self::card(Suit::Hearts, Rank::Queen),
        ]));
    }

    public function testDrawThenDiscardAdvancesTurn(): void
    {
        $state = $this->state([self::card(Suit::Hearts, Rank::Nine), self::card(Suit::Clubs, Rank::Nine)]);

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'discard', 'cards' => '0']);
            self::fail('must draw first');
        } catch (InvalidMoveException) {
        }

        $this->game->applyMove($state, 'p0', ['action' => 'draw']);
        self::assertCount(3, $state->table->hand('p0')->items);

        $this->game->applyMove($state, 'p0', ['action' => 'discard', 'cards' => '0']);
        self::assertSame('p1', $state->currentPlayer()->id);
        self::assertFalse($state->data['hasDrawn']);
    }

    public function testInitialMeldThresholdEnforced(): void
    {
        // meld below 40 points: allowed as pending, but discard is blocked until threshold or takeback
        $state = $this->state([
            self::card(Suit::Hearts, Rank::Two),
            self::card(Suit::Spades, Rank::Two),
            self::card(Suit::Clubs, Rank::Two),
            self::card(Suit::Hearts, Rank::Nine),
            self::card(Suit::Clubs, Rank::Nine),
        ]);
        $this->game->applyMove($state, 'p0', ['action' => 'draw']);
        $this->game->applyMove($state, 'p0', ['action' => 'meld', 'cards' => '0,1,2']);

        self::assertFalse($state->data['hasMelded']['p0']);
        self::assertSame(6, $state->data['turnMeldPoints']);

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'discard', 'cards' => '0']);
            self::fail('discard must be blocked below the initial meld threshold');
        } catch (InvalidMoveException) {
        }

        $this->game->applyMove($state, 'p0', ['action' => 'takeback']);
        self::assertCount(6, $state->table->hand('p0')->items);
        self::assertSame([], $state->table->matching('meld:'));
    }

    public function testOpeningMeldAndLayoff(): void
    {
        $state = $this->state([
            self::card(Suit::Hearts, Rank::King),
            self::card(Suit::Spades, Rank::King),
            self::card(Suit::Clubs, Rank::King),
            self::card(Suit::Diamonds, Rank::Ace),
            self::card(Suit::Hearts, Rank::Ace),
            self::card(Suit::Spades, Rank::Ace),
            self::card(Suit::Diamonds, Rank::King),
            self::card(Suit::Clubs, Rank::Nine),
        ]);
        $this->game->applyMove($state, 'p0', ['action' => 'draw']);

        // 30 points - not open yet
        $this->game->applyMove($state, 'p0', ['action' => 'meld', 'cards' => '0,1,2']);
        self::assertFalse($state->data['hasMelded']['p0']);

        // +33 points - crosses the 40-point threshold (aces re-indexed to 0,1,2 after the first meld)
        $this->game->applyMove($state, 'p0', ['action' => 'meld', 'cards' => '0,1,2']);
        self::assertTrue($state->data['hasMelded']['p0']);

        // lay the fourth king off onto the first meld (hand is now [K♦, 9♣, drawn])
        $this->game->applyMove($state, 'p0', ['action' => 'layoff', 'cards' => '0', 'meld' => 'meld:0']);
        self::assertCount(4, $state->table->zone('meld:0')->items);
    }
}
