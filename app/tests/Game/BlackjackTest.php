<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Card\Rank;
use App\Game\Core\Card\Suit;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Games\Blackjack\GameDefinition;
use App\Game\Games\Blackjack\GameRenderer;
use App\Game\Games\Blackjack\GameRules;

final class BlackjackTest extends GameTestCase
{
    private GameRules $rules;
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->game = new GameDefinition($this->rules, new GameRenderer($this->rules));
    }

    public function testHandValuesWithAces(): void
    {
        self::assertSame(21, $this->rules->value([
            self::card(Suit::Hearts, Rank::Ace),
            self::card(Suit::Clubs, Rank::King),
        ]));
        self::assertTrue($this->rules->isBlackjack([
            self::card(Suit::Hearts, Rank::Ace),
            self::card(Suit::Clubs, Rank::King),
        ]));

        // two aces: one demotes to 1
        self::assertSame(12, $this->rules->value([
            self::card(Suit::Hearts, Rank::Ace),
            self::card(Suit::Clubs, Rank::Ace),
        ]));

        self::assertTrue($this->rules->isBust([
            self::card(Suit::Hearts, Rank::King),
            self::card(Suit::Clubs, Rank::Queen),
            self::card(Suit::Spades, Rank::Five),
        ]));
    }

    /**
     * Single-player state ready to bet, with a rigged deck.
     * Cards are dealt with array_pop, so the LAST entries are dealt first:
     * player gets deck[-1], deck[-2]; dealer gets deck[-3], deck[-4].
     */
    private function bettingState(array $deckTopFirst): GameState
    {
        $state = $this->game->createInitialState(self::players(1), ['startChips' => 100]);
        $state->table->zone('stock')->items = array_reverse($deckTopFirst);

        return $state;
    }

    public function testBetDealsAndPlays(): void
    {
        $state = $this->bettingState([
            self::card(Suit::Hearts, Rank::Ten),   // player card 1
            self::card(Suit::Clubs, Rank::Six),    // player card 2
            self::card(Suit::Spades, Rank::Nine),  // dealer up
            self::card(Suit::Diamonds, Rank::Seven), // dealer hole
            self::card(Suit::Hearts, Rank::Five),  // hit card
        ]);

        $this->game->applyMove($state, 'p0', ['action' => 'bet', 'amount' => 20]);

        self::assertSame('playing', $state->data['phase']);
        self::assertSame(80, $state->data['chips']['p0']);
        self::assertSame(16, $this->rules->value($state->table->hand('p0')->items));

        $this->game->applyMove($state, 'p0', ['action' => 'hit']);
        self::assertSame(21, $this->rules->value($state->table->hand('p0')->items));
        // reaching 21 auto-stands and hands over to the dealer
        self::assertSame('dealer', $state->data['phase']);
    }

    public function testInvalidBetRejected(): void
    {
        $state = $this->bettingState([
            self::card(Suit::Hearts, Rank::Ten),
            self::card(Suit::Clubs, Rank::Six),
            self::card(Suit::Spades, Rank::Nine),
            self::card(Suit::Diamonds, Rank::Seven),
        ]);

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p0', ['action' => 'bet', 'amount' => 15]);
    }

    public function testDealerDrawsToStandThresholdAndSettles(): void
    {
        $state = $this->bettingState([
            self::card(Suit::Hearts, Rank::Ten),
            self::card(Suit::Clubs, Rank::Nine),     // player: 19
            self::card(Suit::Spades, Rank::Six),     // dealer up
            self::card(Suit::Diamonds, Rank::Ten),   // dealer hole: 16
            self::card(Suit::Hearts, Rank::Two),     // dealer draws to 18
        ]);

        $this->game->applyMove($state, 'p0', ['action' => 'bet', 'amount' => 20]);
        $this->game->applyMove($state, 'p0', ['action' => 'stand']);
        self::assertSame('dealer', $state->data['phase']);

        // step 1: reveal, step 2: draw (16 -> 18), step 3: stand -> settle
        $this->game->applyAutoStep($state);
        self::assertTrue($state->data['dealerRevealed']);
        $this->game->applyAutoStep($state);
        self::assertSame(18, $this->rules->value($state->table->zone('dealer')->items));
        $this->game->applyAutoStep($state);
        self::assertSame('settle', $state->data['phase']);

        // settle: player 19 beats dealer 18 -> bet back + winnings
        $this->game->applyAutoStep($state);
        self::assertSame(120, $state->data['chips']['p0']);
    }

    public function testDoubleDoublesBetAndDrawsOneCard(): void
    {
        $state = $this->bettingState([
            self::card(Suit::Hearts, Rank::Five),
            self::card(Suit::Clubs, Rank::Six),      // player: 11
            self::card(Suit::Spades, Rank::Nine),
            self::card(Suit::Diamonds, Rank::Seven),
            self::card(Suit::Hearts, Rank::Ten),     // double card -> 21
        ]);

        $this->game->applyMove($state, 'p0', ['action' => 'bet', 'amount' => 20]);
        $this->game->applyMove($state, 'p0', ['action' => 'double']);

        self::assertSame(40, $state->data['bets']['p0']);
        self::assertSame(60, $state->data['chips']['p0']);
        self::assertCount(3, $state->table->hand('p0')->items);
        self::assertSame('dealer', $state->data['phase']);
    }
}
