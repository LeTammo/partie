<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameStatus;
use App\Game\Games\RowFour\GameDefinition;
use App\Game\Games\RowFour\GameRenderer;
use App\Game\Games\RowFour\GameRules;

final class RowFourTest extends GameTestCase
{
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->game = new GameDefinition(new GameRules(), new GameRenderer(self::translator()));
    }

    public function testInitialStateUsesSettings(): void
    {
        $state = $this->game->createInitialState(self::players(2), ['boardWidth' => 8, 'boardHeight' => 5]);

        self::assertSame(8, $state->board->width);
        self::assertSame(5, $state->board->height);
    }

    public function testDiscFallsToLowestEmptyRow(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        $this->game->applyMove($state, 'p0', ['column' => 3]);
        $this->game->applyMove($state, 'p1', ['column' => 3]);

        self::assertSame('p0', $state->board->get(3, 5)?->ownerId);
        self::assertSame('p1', $state->board->get(3, 4)?->ownerId);
    }

    public function testFullColumnIsRejected(): void
    {
        $state = $this->game->createInitialState(self::players(2));
        foreach (range(0, 5) as $i) {
            $this->game->applyMove($state, 0 === $i % 2 ? 'p0' : 'p1', ['column' => 0]);
        }

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p0', ['column' => 0]);
    }

    public function testVerticalWin(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        // p0 stacks column 0, p1 stacks column 1
        foreach ([0, 1, 0, 1, 0, 1, 0] as $i => $column) {
            $this->game->applyMove($state, 0 === $i % 2 ? 'p0' : 'p1', ['column' => $column]);
        }

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
    }

    public function testHorizontalWinWithCustomConnectCount(): void
    {
        $state = $this->game->createInitialState(self::players(2), ['connectCount' => 3]);

        $this->game->applyMove($state, 'p0', ['column' => 0]);
        $this->game->applyMove($state, 'p1', ['column' => 0]);
        $this->game->applyMove($state, 'p0', ['column' => 1]);
        $this->game->applyMove($state, 'p1', ['column' => 1]);
        $this->game->applyMove($state, 'p0', ['column' => 2]);

        self::assertSame('p0', $state->winnerId);
    }

    public function testDiagonalWinDetection(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        // build a / diagonal for p0: (0,5) (1,4) (2,3) (3,2)
        $moves = [
            ['p0', 0], ['p1', 1], ['p0', 1], ['p1', 2], ['p0', 2], ['p1', 3],
            ['p0', 2], ['p1', 3], ['p0', 3], ['p1', 0], ['p0', 3],
        ];
        foreach ($moves as [$player, $column]) {
            $this->game->applyMove($state, $player, ['column' => $column]);
        }

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
    }
}
