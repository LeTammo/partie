<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameStatus;
use App\Game\Games\Checkers\GameDefinition;
use App\Game\Games\Checkers\GameRenderer;
use App\Game\Games\Checkers\GameRules;

final class CheckersTest extends GameTestCase
{
    private GameRules $rules;
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->game = new GameDefinition($this->rules, new GameRenderer($this->rules));
    }

    public function testInitialSetup(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        self::assertSame(12, $state->board->countTokensOf('p0'));
        self::assertSame(12, $state->board->countTokensOf('p1'));
        foreach ($state->board->tokens() as ['x' => $x, 'y' => $y]) {
            self::assertTrue($this->rules->isDarkSquare($x, $y));
        }
    }

    public function testSimpleForwardMove(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        // p0 moves down: a piece on row 2 steps to row 3
        $this->game->applyMove($state, 'p0', ['from' => 'cell:1:2', 'to' => 'cell:2:3']);

        self::assertNull($state->board->get(1, 2));
        self::assertSame('p0', $state->board->get(2, 3)?->ownerId);
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testBackwardMoveIsRejectedForMen(): void
    {
        $state = $this->game->createInitialState(self::players(2));
        $this->game->applyMove($state, 'p0', ['from' => 'cell:1:2', 'to' => 'cell:2:3']);
        $this->game->applyMove($state, 'p1', ['from' => 'cell:0:5', 'to' => 'cell:1:4']);

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p0', ['from' => 'cell:2:3', 'to' => 'cell:1:2']);
    }

    public function testJumpCaptureRemovesVictim(): void
    {
        $state = $this->game->createInitialState(self::players(2));
        $this->game->applyMove($state, 'p0', ['from' => 'cell:1:2', 'to' => 'cell:2:3']);
        $this->game->applyMove($state, 'p1', ['from' => 'cell:4:5', 'to' => 'cell:3:4']);

        // p0 jumps 2,3 -> 4,5 capturing the piece at 3,4
        $this->game->applyMove($state, 'p0', ['from' => 'cell:2:3', 'to' => 'cell:4:5']);

        self::assertNull($state->board->get(3, 4));
        self::assertSame('p0', $state->board->get(4, 5)?->ownerId);
        self::assertSame(11, $state->board->countTokensOf('p1'));
    }

    public function testMovesForPieceOffersStepsAndJumps(): void
    {
        $state = $this->game->createInitialState(self::players(2));
        $board = $state->board;

        $moves = $this->rules->movesForPiece($board, 1, 2, 1);
        $targets = array_map(static fn (array $m): string => $m['toX'].':'.$m['toY'], $moves);

        self::assertContains('0:3', $targets);
        self::assertContains('2:3', $targets);
    }

    public function testPromotionOnBackRank(): void
    {
        $state = $this->game->createInitialState(self::players(2));
        $board = $state->board;
        $token = $board->get(1, 2);

        self::assertFalse($this->rules->shouldPromote($token, 6, 1, $board->height));
        self::assertTrue($this->rules->shouldPromote($token, 7, 1, $board->height));

        $token->promote(GameRules::KING);
        self::assertFalse($this->rules->shouldPromote($token, 7, 1, $board->height));
    }

    public function testWinWhenOpponentHasNoPieces(): void
    {
        $state = $this->game->createInitialState(self::players(2));
        $board = $state->board;

        // strip the board down to a single forced capture
        foreach ($board->tokens() as ['x' => $x, 'y' => $y]) {
            $board->remove($x, $y);
        }
        $p0 = $this->game->createInitialState(self::players(2))->board->get(1, 2);
        $p1 = $this->game->createInitialState(self::players(2))->board->get(0, 5);
        $board->place(2, 3, $p0);
        $board->place(3, 4, $p1);

        $this->game->applyMove($state, 'p0', ['from' => 'cell:2:3', 'to' => 'cell:4:5']);

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
    }
}
