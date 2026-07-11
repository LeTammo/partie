<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameStatus;
use App\Game\Games\TicTacToe\GameDefinition;
use App\Game\Games\TicTacToe\GameRenderer;
use App\Game\Games\TicTacToe\GameRules;

final class TicTacToeTest extends GameTestCase
{
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->game = new GameDefinition(new GameRules(), new GameRenderer());
    }

    public function testInitialState(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        self::assertSame(3, $state->board->width);
        self::assertSame(3, $state->board->height);
        self::assertSame('x', $state->data['variants']['p0']);
        self::assertSame('o', $state->data['variants']['p1']);
        self::assertSame(0, $state->currentTurnIndex);
    }

    public function testPlacingAdvancesTurn(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        $this->game->applyMove($state, 'p0', ['x' => 1, 'y' => 1]);

        self::assertSame('p0', $state->board->get(1, 1)?->ownerId);
        self::assertSame('x', $state->board->get(1, 1)?->variant);
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testCannotPlaceOutOfTurn(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p1', ['x' => 0, 'y' => 0]);
    }

    public function testCannotPlaceOnTakenCell(): void
    {
        $state = $this->game->createInitialState(self::players(2));
        $this->game->applyMove($state, 'p0', ['x' => 0, 'y' => 0]);

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p1', ['x' => 0, 'y' => 0]);
    }

    public function testRowWinFinishesGame(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        $this->game->applyMove($state, 'p0', ['x' => 0, 'y' => 0]);
        $this->game->applyMove($state, 'p1', ['x' => 0, 'y' => 1]);
        $this->game->applyMove($state, 'p0', ['x' => 1, 'y' => 0]);
        $this->game->applyMove($state, 'p1', ['x' => 1, 'y' => 1]);
        $this->game->applyMove($state, 'p0', ['x' => 2, 'y' => 0]);

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
    }

    public function testDiagonalWin(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        $this->game->applyMove($state, 'p0', ['x' => 0, 'y' => 0]);
        $this->game->applyMove($state, 'p1', ['x' => 1, 'y' => 0]);
        $this->game->applyMove($state, 'p0', ['x' => 1, 'y' => 1]);
        $this->game->applyMove($state, 'p1', ['x' => 2, 'y' => 0]);
        $this->game->applyMove($state, 'p0', ['x' => 2, 'y' => 2]);

        self::assertSame('p0', $state->winnerId);
    }

    public function testFullBoardIsDraw(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        // x o x / x o o / o x x - no line for either player
        foreach ([[0, 0], [1, 0], [2, 0], [1, 1], [0, 1], [2, 1], [1, 2], [0, 2], [2, 2]] as $i => [$x, $y]) {
            $this->game->applyMove($state, 0 === $i % 2 ? 'p0' : 'p1', ['x' => $x, 'y' => $y]);
        }

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertNull($state->winnerId);
        self::assertTrue($state->draw);
    }
}
