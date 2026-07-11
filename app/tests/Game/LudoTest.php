<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameStatus;
use App\Game\Games\Ludo\GameDefinition;
use App\Game\Games\Ludo\GameRenderer;
use App\Game\Games\Ludo\GameRules;

final class LudoTest extends GameTestCase
{
    private GameRules $rules;
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->game = new GameDefinition($this->rules, new GameRenderer($this->rules));
    }

    /**
     * @param list<int> $p0
     * @param list<int> $p1
     */
    private function state(array $p0, array $p1, ?int $roll = null): \App\Game\Core\Model\GameState
    {
        $state = $this->game->createInitialState(self::players(2));
        $state->data['pawns']['p0'] = $p0;
        $state->data['pawns']['p1'] = $p1;
        $state->data['roll'] = $roll;

        return $state;
    }

    public function testInitialStateAllPawnsInBase(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        self::assertSame([-1, -1, -1, -1], $state->data['pawns']['p0']);
        self::assertNull($state->data['roll']);
    }

    public function testRingGeometry(): void
    {
        self::assertSame(0, $this->rules->startIndex(0));
        self::assertSame(10, $this->rules->startIndex(1));
        self::assertSame(5, $this->rules->ringIndexFor(0, 5));
        self::assertSame(3, $this->rules->ringIndexFor(1, 33)); // wraps around
        self::assertNull($this->rules->ringIndexFor(0, GameRules::RING_LENGTH)); // home lane, not on ring
    }

    public function testReleaseRequiresASix(): void
    {
        $seats = ['p0' => 0, 'p1' => 1];
        $pawns = ['p0' => [-1, -1, -1, -1], 'p1' => [-1, -1, -1, -1]];

        self::assertSame([], $this->rules->legalMoves($pawns, $seats, 'p0', 3));
        self::assertSame([0, 1, 2, 3], $this->rules->legalMoves($pawns, $seats, 'p0', 6));
    }

    public function testOwnPawnBlocksStartSquare(): void
    {
        $seats = ['p0' => 0, 'p1' => 1];
        $pawns = ['p0' => [0, -1, -1, -1], 'p1' => [-1, -1, -1, -1]];

        // pawn 0 sits on p0's start square (progress 0), so no release - but pawn 0 itself can move
        self::assertSame([0], $this->rules->legalMoves($pawns, $seats, 'p0', 6));
    }

    public function testCannotOvershootFinish(): void
    {
        $seats = ['p0' => 0, 'p1' => 1];
        $pawns = ['p0' => [GameRules::FINISH_PROGRESS - 1, -1, -1, -1], 'p1' => [-1, -1, -1, -1]];

        self::assertSame([0], $this->rules->legalMoves($pawns, $seats, 'p0', 1));
        self::assertSame([], $this->rules->legalMoves($pawns, $seats, 'p0', 2));
    }

    public function testMoveCapturesOpponent(): void
    {
        // p1 (seat 1) at progress 0 = ring index 10; p0 at progress 6 moving 4 lands on ring index 10
        $state = $this->state([6, -1, -1, -1], [0, -1, -1, -1], roll: 4);

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'pawn' => 0]);

        self::assertSame(10, $state->data['pawns']['p0'][0]);
        self::assertSame(-1, $state->data['pawns']['p1'][0]); // captured back to base
    }

    public function testMoveWithoutRollIsRejected(): void
    {
        $state = $this->state([0, -1, -1, -1], [-1, -1, -1, -1], roll: null);

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'pawn' => 0]);
    }

    public function testSixGrantsAnotherTurn(): void
    {
        $state = $this->state([0, -1, -1, -1], [-1, -1, -1, -1], roll: 6);

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'pawn' => 0]);

        self::assertSame('p0', $state->currentPlayer()->id);
        self::assertNull($state->data['roll']);
    }

    public function testFinishingLastPawnWinsGame(): void
    {
        // home slots are exclusive: three pawns parked on 43/42/41, the last one enters slot 40
        $state = $this->state([43, 42, 41, 38], [-1, -1, -1, -1], roll: 2);

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'pawn' => 3]);

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
    }

    public function testHomeLaneSlotCollisionIsIllegal(): void
    {
        $seats = ['p0' => 0, 'p1' => 1];
        $lane = GameRules::RING_LENGTH; // first home-lane slot
        $pawns = ['p0' => [$lane, GameRules::RING_LENGTH - 1, -1, -1], 'p1' => [-1, -1, -1, -1]];

        // pawn 1 would land on the occupied home slot with a roll of 1
        $legal = $this->rules->legalMoves($pawns, $seats, 'p0', 1);
        self::assertNotContains(1, $legal);
    }
}
