<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Card\Rank;
use App\Game\Core\Card\Suit;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Games\MauMau\GameDefinition;
use App\Game\Games\MauMau\GameRenderer;
use App\Game\Games\MauMau\GameRules;
use App\Game\Games\MauMau\Options;

final class MauMauTest extends GameTestCase
{
    private GameRules $rules;
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->game = new GameDefinition($this->rules, new GameRenderer($this->rules));
    }

    /**
     * Deterministic 2-player state; hands and discard are set explicitly.
     */
    private function state(array $handP0, array $handP1, \App\Game\Core\Card\PlayingCard $top, array $settings = []): GameState
    {
        $state = $this->game->createInitialState(self::players(2), $settings);
        $state->table->hand('p0')->items = $handP0;
        $state->table->hand('p1')->items = $handP1;
        $state->table->zone('discard')->items = [$top];
        $state->table->zone('stock')->items = [
            self::card(Suit::Clubs, Rank::Nine),
            self::card(Suit::Diamonds, Rank::Ten),
            self::card(Suit::Spades, Rank::Queen),
        ];

        return $state;
    }

    private function defaultOptions(): Options
    {
        return new Options(
            skipRank: Rank::Eight,
            drawRank: Rank::Seven,
            stackDraw: true,
            stackSkip: false,
            allowRewish: false,
        );
    }

    public function testPlayableMatchesSuitOrRank(): void
    {
        $top = self::card(Suit::Hearts, Rank::Nine);
        $options = $this->defaultOptions();

        self::assertTrue($this->rules->playable(self::card(Suit::Hearts, Rank::King), $top, null, 0, false, 0, $options));
        self::assertTrue($this->rules->playable(self::card(Suit::Clubs, Rank::Nine), $top, null, 0, false, 0, $options));
        self::assertFalse($this->rules->playable(self::card(Suit::Clubs, Rank::King), $top, null, 0, false, 0, $options));
        // jack is always playable (top is not a jack)
        self::assertTrue($this->rules->playable(self::card(Suit::Clubs, Rank::Jack), $top, null, 0, false, 0, $options));
    }

    public function testWishedSuitRestrictsPlays(): void
    {
        $top = self::card(Suit::Hearts, Rank::Jack);
        $options = $this->defaultOptions();

        self::assertTrue($this->rules->playable(self::card(Suit::Clubs, Rank::Nine), $top, 'clubs', 0, false, 0, $options));
        self::assertFalse($this->rules->playable(self::card(Suit::Hearts, Rank::Nine), $top, 'clubs', 0, false, 0, $options));
    }

    public function testPendingDrawOnlyStackable(): void
    {
        $top = self::card(Suit::Hearts, Rank::Seven);
        $options = $this->defaultOptions();

        self::assertTrue($this->rules->playable(self::card(Suit::Clubs, Rank::Seven), $top, null, 2, false, 0, $options));
        self::assertFalse($this->rules->playable(self::card(Suit::Hearts, Rank::King), $top, null, 2, false, 0, $options));
        // once you started drawing the penalty you're locked in
        self::assertFalse($this->rules->playable(self::card(Suit::Clubs, Rank::Seven), $top, null, 1, true, 0, $options));
    }

    public function testPlayingSevenAddsPenalty(): void
    {
        $state = $this->state(
            [self::card(Suit::Hearts, Rank::Seven), self::card(Suit::Clubs, Rank::King)],
            [self::card(Suit::Spades, Rank::Nine)],
            self::card(Suit::Hearts, Rank::Nine),
        );

        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);

        self::assertSame(2, $state->data['pendingDraw']);
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testPenaltyDrawCountsDown(): void
    {
        $state = $this->state(
            [self::card(Suit::Clubs, Rank::King)],
            [self::card(Suit::Spades, Rank::Nine)],
            self::card(Suit::Hearts, Rank::Seven),
        );
        $state->data['pendingDraw'] = 2;
        $state->currentTurnIndex = 1;

        $this->game->applyMove($state, 'p1', ['action' => 'draw']);
        self::assertSame(1, $state->data['pendingDraw']);
        self::assertSame('p1', $state->currentPlayer()->id);
        self::assertTrue($state->data['penaltyLocked']);

        $this->game->applyMove($state, 'p1', ['action' => 'draw']);
        self::assertSame(0, $state->data['pendingDraw']);
        self::assertSame('p0', $state->currentPlayer()->id);
        self::assertCount(3, $state->table->hand('p1')->items);
    }

    public function testEightSkipsNextPlayer(): void
    {
        $state = $this->state(
            [self::card(Suit::Hearts, Rank::Eight), self::card(Suit::Clubs, Rank::King)],
            [self::card(Suit::Spades, Rank::Nine)],
            self::card(Suit::Hearts, Rank::Nine),
        );

        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);

        // p1 is skipped, back to p0 (2 players, stackSkip off)
        self::assertSame('p0', $state->currentPlayer()->id);
        self::assertSame(0, $state->data['pendingSkip']);
    }

    public function testStackedSkipAutoSkipsPlayerWithoutMatchingCard(): void
    {
        $state = $this->state(
            [self::card(Suit::Hearts, Rank::Eight), self::card(Suit::Clubs, Rank::King)],
            [self::card(Suit::Spades, Rank::Nine)],
            self::card(Suit::Hearts, Rank::Nine),
            ['stackSkip' => true],
        );

        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);

        // p1 has no eight to extend the skip, so they are auto-skipped
        // without needing to click "pass" — it's p0's turn again.
        self::assertSame('p0', $state->currentPlayer()->id);
        self::assertSame(0, $state->data['pendingSkip']);
    }

    public function testStackedSkipLetsPlayerExtend(): void
    {
        $state = $this->state(
            [self::card(Suit::Hearts, Rank::Eight), self::card(Suit::Diamonds, Rank::Eight)],
            [self::card(Suit::Clubs, Rank::Eight), self::card(Suit::Spades, Rank::Nine)],
            self::card(Suit::Hearts, Rank::Nine),
            ['stackSkip' => true],
        );

        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);

        // p1 has an eight and is not auto-skipped; they must act.
        self::assertSame('p1', $state->currentPlayer()->id);
        self::assertSame(1, $state->data['pendingSkip']);

        $this->game->applyMove($state, 'p1', ['action' => 'play', 'card' => 0]);

        // p1 stacked their own eight; p0 must now respond in turn.
        self::assertSame('p0', $state->currentPlayer()->id);
        self::assertSame(2, $state->data['pendingSkip']);
    }

    public function testPassingOnPendingSkipFullyResolvesIt(): void
    {
        $state = $this->state(
            [self::card(Suit::Clubs, Rank::King)],
            [self::card(Suit::Spades, Rank::Nine)],
            self::card(Suit::Hearts, Rank::Nine),
            ['stackSkip' => true],
        );
        $state->data['pendingSkip'] = 2;
        $state->currentTurnIndex = 1;

        $this->game->applyMove($state, 'p1', ['action' => 'pass']);

        // A pass fully absorbs the pending skip in one turn, it does not
        // decrement by one and roll the remainder onto the next player.
        self::assertSame('p0', $state->currentPlayer()->id);
        self::assertSame(0, $state->data['pendingSkip']);
    }

    public function testJackRequiresWish(): void
    {
        $state = $this->state(
            [self::card(Suit::Clubs, Rank::Jack), self::card(Suit::Clubs, Rank::King)],
            [self::card(Suit::Spades, Rank::Nine)],
            self::card(Suit::Hearts, Rank::Nine),
        );

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);
            self::fail('expected InvalidMoveException');
        } catch (InvalidMoveException) {
        }

        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0, 'wish' => 'spades']);
        self::assertSame('spades', $state->data['wishedSuit']);
    }

    public function testDrawThenPass(): void
    {
        $state = $this->state(
            [self::card(Suit::Clubs, Rank::King)],
            [self::card(Suit::Spades, Rank::Nine)],
            self::card(Suit::Hearts, Rank::Nine),
        );

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'pass']);
            self::fail('cannot pass before drawing');
        } catch (InvalidMoveException) {
        }

        $this->game->applyMove($state, 'p0', ['action' => 'draw']);
        self::assertCount(2, $state->table->hand('p0')->items);

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'draw']);
            self::fail('cannot draw twice');
        } catch (InvalidMoveException) {
        }

        $this->game->applyMove($state, 'p0', ['action' => 'pass']);
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testPlayingLastCardWins(): void
    {
        $state = $this->state(
            [self::card(Suit::Hearts, Rank::King)],
            [self::card(Suit::Spades, Rank::Nine)],
            self::card(Suit::Hearts, Rank::Nine),
        );

        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
    }
}
