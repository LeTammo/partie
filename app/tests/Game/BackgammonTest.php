<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Token;
use App\Game\Games\Backgammon\GameDefinition;
use App\Game\Games\Backgammon\GameRenderer;
use App\Game\Games\Backgammon\GameRules;

final class BackgammonTest extends GameTestCase
{
    private GameRules $rules;
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->game = new GameDefinition($this->rules, new GameRenderer($this->rules));
    }

    private function state(): GameState
    {
        return $this->game->createInitialState(self::players(2));
    }

    public function testInitialLayoutHasFifteenCheckersPerPlayerOnStandardPoints(): void
    {
        $state = $this->state();
        $table = $state->table;

        foreach ($state->players as $player) {
            $total = 0;
            foreach (range(0, 23) as $point) {
                foreach ($table->zone('point:'.$point)->items as $token) {
                    if ($token->ownerId === $player->id) {
                        ++$total;
                    }
                }
            }
            self::assertSame(15, $total, $player->id.' should start with 15 checkers');
        }

        // seat 0 starts 2 on point 23, seat 1 mirrored on point 0
        self::assertCount(2, $table->zone('point:23')->items);
        self::assertCount(2, $table->zone('point:0')->items);
        self::assertSame('p0', $table->zone('point:23')->items[0]->ownerId);
        self::assertSame('p1', $table->zone('point:0')->items[0]->ownerId);
    }

    public function testMoveConsumesTheMatchingDieAndAdvancesWhenNoMoreMoves(): void
    {
        $state = $this->state();
        $state->data['remainingDice'] = [3];

        // p0 (seat 0, direction -1): point 5 -> point 2 is a roll of 3, and point 2 is empty
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'point:5', 'to' => 'point:2']);

        self::assertCount(4, $state->table->zone('point:5')->items);
        self::assertCount(1, $state->table->zone('point:2')->items);
        self::assertSame([], $state->data['remainingDice']);
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testMovingOntoAnOpponentBlotSendsItToTheBar(): void
    {
        $state = $this->state();
        $table = $state->table;
        // clear point 2 and place a lone p1 blot there
        $table->zone('point:2')->clear();
        $table->zone('point:2')->push(new Token(ownerId: 'p1', outerColor: '#000', centerColor: '#fff'));
        $state->data['remainingDice'] = [3];

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'point:5', 'to' => 'point:2']);

        self::assertCount(1, $table->zone('point:2')->items);
        self::assertSame('p0', $table->zone('point:2')->items[0]->ownerId);
        self::assertCount(1, $table->zone('bar:p1')->items);
    }

    public function testCheckerOnTheBarMustEnterBeforeAnyOtherMove(): void
    {
        $state = $this->state();
        $table = $state->table;
        $table->zone('bar:p0')->push(new Token(ownerId: 'p0', outerColor: '#000', centerColor: '#fff'));
        $state->data['remainingDice'] = [3, 4];

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'point:5', 'to' => 'point:2']);
            self::fail('must enter from the bar first');
        } catch (InvalidMoveException) {
            self::assertCount(1, $table->zone('bar:p0')->items);
        }

        // entering with roll 3 lands on absolute point 21 (entryTarget = 24 - roll)
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'bar:p0', 'to' => 'point:21']);
        self::assertTrue($table->zone('bar:p0')->isEmpty());
        self::assertCount(1, $table->zone('point:21')->items);
    }

    public function testBearOffRequiresAllCheckersHome(): void
    {
        $state = $this->state();
        $table = $state->table;

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'point:18', 'to' => 'off:p0']);
            self::fail('bear off should be illegal before all checkers are home');
        } catch (InvalidMoveException) {
            self::assertCount(0, $table->zone('off:p0')->items);
        }
    }

    public function testExactBearOffWins(): void
    {
        $state = $this->state();
        $table = $state->table;

        // clear the board and put 14 of p0's checkers already off, one checker left on point 0
        foreach (range(0, 23) as $point) {
            $table->zone('point:'.$point)->clear();
        }
        for ($i = 0; $i < 14; ++$i) {
            $table->zone('off:p0')->push(new Token(ownerId: 'p0', outerColor: '#000', centerColor: '#fff'));
        }
        $table->zone('point:0')->push(new Token(ownerId: 'p0', outerColor: '#000', centerColor: '#fff'));
        $state->data['remainingDice'] = [1];

        // seat 0: normalTarget(0, 1) = -1 = exact bear-off target
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'point:0', 'to' => 'off:p0']);

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
        self::assertSame(15, $table->zone('off:p0')->count());
    }

    public function testDoublesGiveFourMoves(): void
    {
        $state = $this->state();
        $state->data['remainingDice'] = [4, 4, 4, 4];

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'point:5', 'to' => 'point:1']);

        self::assertSame([4, 4, 4], $state->data['remainingDice']);
        self::assertSame('p0', $state->currentPlayer()->id);
    }
}
