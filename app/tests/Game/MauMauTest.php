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
        $state->data['hands']['p0'] = $handP0;
        $state->data['hands']['p1'] = $handP1;
        $state->data['discard'] = [$top];
        $state->data['drawPile'] = [
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
        self::assertCount(3, $state->data['hands']['p1']);
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
        self::assertCount(2, $state->data['hands']['p0']);

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
