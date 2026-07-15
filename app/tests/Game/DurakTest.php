<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Card\Rank;
use App\Game\Core\Card\Suit;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Games\Durak\GameDefinition;
use App\Game\Games\Durak\GameRenderer;
use App\Game\Games\Durak\GameRules;

final class DurakTest extends GameTestCase
{
    private GameRules $rules;
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->game = new GameDefinition($this->rules, new GameRenderer($this->rules));
    }

    private function state(array $handP0, array $handP1, Suit $trump = Suit::Hearts): GameState
    {
        $state = $this->game->createInitialState(self::players(2));
        $state->table->hand('p0')->items = $handP0;
        $state->table->hand('p1')->items = $handP1;
        $state->data['trumpSuit'] = $trump->value;
        $state->table->zone('stock')->items = [];
        $state->data['attackerId'] = 'p0';
        $state->data['defenderId'] = 'p1';
        $state->currentTurnIndex = 0;

        return $state;
    }

    public function testInitialStateDealsSixEachAndSetsAttacker(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        self::assertCount(6, $state->table->hand('p0')->items);
        self::assertCount(6, $state->table->hand('p1')->items);
        self::assertSame('p0', $state->data['attackerId']);
        self::assertSame('p1', $state->data['defenderId']);
        self::assertSame(0, $state->currentTurnIndex);
        // 36 - 12 dealt = 24 left in stock (including the trump card)
        self::assertCount(24, $state->table->zone('stock')->items);
    }

    public function testAttackerCanOpenWithAnyCard(): void
    {
        $state = $this->state(
            [self::card(Suit::Clubs, Rank::Eight)],
            [self::card(Suit::Clubs, Rank::Nine)],
        );

        $this->game->applyMove($state, 'p0', ['action' => 'attack', 'card' => 0]);

        self::assertCount(1, $state->table->zone('attack')->items);
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testSecondAttackCardMustMatchARankOnTheTable(): void
    {
        $state = $this->state(
            [self::card(Suit::Clubs, Rank::Eight), self::card(Suit::Spades, Rank::King)],
            [self::card(Suit::Clubs, Rank::Nine), self::card(Suit::Spades, Rank::Nine)],
        );
        $state->table->zone('attack')->items = [
            ['attack' => self::card(Suit::Clubs, Rank::Eight), 'defend' => self::card(Suit::Clubs, Rank::Nine)],
        ];
        // pretend the eight was already removed from hand
        $state->table->hand('p0')->items = [self::card(Suit::Spades, Rank::King)];

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'attack', 'card' => 0]);
            self::fail('a king does not match any rank on the table');
        } catch (InvalidMoveException) {
            self::assertCount(1, $state->table->hand('p0')->items);
        }
    }

    public function testDefenderMustBeatWithHigherSameSuitOrTrump(): void
    {
        $state = $this->state([], [
            self::card(Suit::Clubs, Rank::Seven),
            self::card(Suit::Hearts, Rank::Two),
        ], trump: Suit::Hearts);
        $state->table->zone('attack')->items = [
            ['attack' => self::card(Suit::Clubs, Rank::Nine), 'defend' => null],
        ];
        $state->data['defenderId'] = 'p1';
        $state->currentTurnIndex = 1;

        try {
            $this->game->applyMove($state, 'p1', ['action' => 'defend', 'pair' => 0, 'card' => 0]);
            self::fail('a lower club cannot beat a higher club');
        } catch (InvalidMoveException) {
        }

        $this->game->applyMove($state, 'p1', ['action' => 'defend', 'pair' => 0, 'card' => 1]);

        self::assertNotNull($state->table->zone('attack')->items[0]['defend']);
        self::assertSame('p0', $state->currentPlayer()->id);
    }

    public function testTakingMovesTableIntoDefenderHandAndKeepsAttacker(): void
    {
        $state = $this->state([self::card(Suit::Diamonds, Rank::King)], []);
        $state->table->zone('attack')->items = [
            ['attack' => self::card(Suit::Clubs, Rank::Nine), 'defend' => null],
        ];
        $state->data['defenderId'] = 'p1';
        $state->currentTurnIndex = 1;

        $this->game->applyMove($state, 'p1', ['action' => 'take']);

        self::assertTrue($state->table->zone('attack')->isEmpty());
        self::assertCount(1, $state->table->hand('p1')->items);
        self::assertSame('p0', $state->currentPlayer()->id);
        self::assertSame('p0', $state->data['attackerId']);
        self::assertSame('p1', $state->data['defenderId']);
    }

    public function testDoneAfterFullDefenseSwapsRolesAndDiscards(): void
    {
        $state = $this->state([self::card(Suit::Diamonds, Rank::Two)], [self::card(Suit::Diamonds, Rank::Three)]);
        $state->table->zone('attack')->items = [
            ['attack' => self::card(Suit::Clubs, Rank::Nine), 'defend' => self::card(Suit::Clubs, Rank::King)],
        ];

        $this->game->applyMove($state, 'p0', ['action' => 'done']);

        self::assertTrue($state->table->zone('attack')->isEmpty());
        self::assertCount(2, $state->table->zone('discard')->items);
        self::assertSame('p1', $state->data['attackerId']);
        self::assertSame('p0', $state->data['defenderId']);
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testEmptyHandWithEmptyStockWins(): void
    {
        $state = $this->state([], [self::card(Suit::Spades, Rank::Two)]);
        $state->table->zone('attack')->items = [
            ['attack' => self::card(Suit::Clubs, Rank::Nine), 'defend' => self::card(Suit::Clubs, Rank::King)],
        ];

        $this->game->applyMove($state, 'p0', ['action' => 'done']);

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
    }
}
