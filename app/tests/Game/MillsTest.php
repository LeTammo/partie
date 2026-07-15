<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Token;
use App\Game\Core\View\MoveMap;
use App\Game\Games\Mills\GameDefinition;
use App\Game\Games\Mills\GameRenderer;
use App\Game\Games\Mills\GameRules;

final class MillsTest extends GameTestCase
{
    private GameRules $rules;
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->game = new GameDefinition($this->rules, new GameRenderer($this->rules));
    }

    private function state(array $settings = []): GameState
    {
        return $this->game->createInitialState(self::players(2), $settings);
    }

    public function testInitialStateStartsInPlacingPhase(): void
    {
        $state = $this->state();

        self::assertSame('placing', $state->data['phase']);
        self::assertSame(0, $state->data['placedCount']['p0']);
        self::assertFalse($state->data['pendingRemoval']);
    }

    public function testPlacingOnTakenOrInvalidPointFails(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'place', 'x' => 0, 'y' => 0]);

        try {
            $this->game->applyMove($state, 'p1', ['action' => 'place', 'x' => 0, 'y' => 0]);
            self::fail('cannot place on an occupied point');
        } catch (InvalidMoveException) {
            self::assertSame('p0', $state->board->get(0, 0)->ownerId);
        }

        try {
            // (1,0) is not one of the 24 valid points
            $this->game->applyMove($state, 'p1', ['action' => 'place', 'x' => 1, 'y' => 0]);
            self::fail('cannot place off the point graph');
        } catch (InvalidMoveException) {
            self::assertNull($state->board->get(1, 0));
        }
    }

    public function testFormingAMillGrantsRemoval(): void
    {
        $state = $this->state();
        // p0 places points 0, 1 (top edge of outer ring), p1 places elsewhere
        $this->game->applyMove($state, 'p0', ['action' => 'place', 'x' => 0, 'y' => 0]); // point 0
        $this->game->applyMove($state, 'p1', ['action' => 'place', 'x' => 6, 'y' => 6]); // point 4
        $this->game->applyMove($state, 'p0', ['action' => 'place', 'x' => 3, 'y' => 0]); // point 1
        $this->game->applyMove($state, 'p1', ['action' => 'place', 'x' => 3, 'y' => 6]); // point 5
        // completes the mill [0, 1, 2]
        $this->game->applyMove($state, 'p0', ['action' => 'place', 'x' => 6, 'y' => 0]); // point 2

        self::assertTrue($state->data['pendingRemoval']);
        // still p0's turn (removal pending)
        self::assertSame('p0', $state->currentPlayer()->id);

        $this->game->applyMove($state, 'p0', ['remove' => MoveMap::cellKey(6, 6)]);

        self::assertFalse($state->data['pendingRemoval']);
        self::assertNull($state->board->get(6, 6));
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testMillProtectedPiecesCannotBeRemovedUnlessAllInMills(): void
    {
        $state = $this->state();
        $board = $state->board;

        // manually build: p1 has a complete mill [8,9,10] plus one free piece at point 16
        $seatColors = GameRules::TOKEN_COLORS;
        foreach ([8, 9, 10, 16] as $point) {
            [$x, $y] = GameRules::POINTS[$point];
            $board->place($x, $y, new Token(ownerId: 'p1', outerColor: $seatColors[1][0], centerColor: $seatColors[1][1]));
        }

        $candidates = $this->rules->removableCandidates($board, 'p1');

        self::assertSame([16], $candidates);
    }

    public function testFlyingAllowsAnyDestinationAtThreePieces(): void
    {
        $state = $this->state();
        $board = $state->board;
        $state->data['phase'] = 'moving';
        $state->data['placedCount'] = ['p0' => 9, 'p1' => 9];

        $seatColors = GameRules::TOKEN_COLORS;
        foreach ([0, 1, 2] as $point) {
            [$x, $y] = GameRules::POINTS[$point];
            $board->place($x, $y, new Token(ownerId: 'p0', outerColor: $seatColors[0][0], centerColor: $seatColors[0][1]));
        }
        foreach ([4, 5] as $point) {
            [$x, $y] = GameRules::POINTS[$point];
            $board->place($x, $y, new Token(ownerId: 'p1', outerColor: $seatColors[1][0], centerColor: $seatColors[1][1]));
        }

        // p0 has exactly 3 pieces -> flying: point 0 (0,0) can jump to the far point 20 (4,4), not adjacent
        [$fromX, $fromY] = GameRules::POINTS[0];
        [$toX, $toY] = GameRules::POINTS[20];

        $this->game->applyMove($state, 'p0', [
            'action' => 'move',
            'from' => MoveMap::cellKey($fromX, $fromY),
            'to' => MoveMap::cellKey($toX, $toY),
        ]);

        self::assertNull($board->get($fromX, $fromY));
        self::assertNotNull($board->get($toX, $toY));
    }

    public function testNonFlyingMoveMustBeAdjacent(): void
    {
        $state = $this->state();
        $board = $state->board;
        $state->data['phase'] = 'moving';
        $state->data['placedCount'] = ['p0' => 9, 'p1' => 9];

        $seatColors = GameRules::TOKEN_COLORS;
        foreach ([0, 1, 2, 3] as $point) {
            [$x, $y] = GameRules::POINTS[$point];
            $board->place($x, $y, new Token(ownerId: 'p0', outerColor: $seatColors[0][0], centerColor: $seatColors[0][1]));
        }
        foreach ([4, 5] as $point) {
            [$x, $y] = GameRules::POINTS[$point];
            $board->place($x, $y, new Token(ownerId: 'p1', outerColor: $seatColors[1][0], centerColor: $seatColors[1][1]));
        }

        [$fromX, $fromY] = GameRules::POINTS[0];
        [$toX, $toY] = GameRules::POINTS[20]; // not adjacent, and p0 has 4 pieces (no flying)

        try {
            $this->game->applyMove($state, 'p0', [
                'action' => 'move',
                'from' => MoveMap::cellKey($fromX, $fromY),
                'to' => MoveMap::cellKey($toX, $toY),
            ]);
            self::fail('expected InvalidMoveException for a non-adjacent slide');
        } catch (InvalidMoveException) {
            self::assertNotNull($board->get($fromX, $fromY));
        }
    }

    public function testReducingOpponentBelowThreeWinsTheGame(): void
    {
        $state = $this->state();
        $board = $state->board;
        $state->data['phase'] = 'moving';
        $state->data['placedCount'] = ['p0' => 9, 'p1' => 9];

        $seatColors = GameRules::TOKEN_COLORS;
        // p0: a mill about to be completed by sliding point 1 -> already has 0 and 2 elsewhere... simpler:
        // give p0 an existing mill it can re-form is complex; instead directly exercise finish via remove().
        foreach ([0, 1, 2] as $point) {
            [$x, $y] = GameRules::POINTS[$point];
            $board->place($x, $y, new Token(ownerId: 'p0', outerColor: $seatColors[0][0], centerColor: $seatColors[0][1]));
        }
        foreach ([16, 17] as $point) {
            [$x, $y] = GameRules::POINTS[$point];
            $board->place($x, $y, new Token(ownerId: 'p1', outerColor: $seatColors[1][0], centerColor: $seatColors[1][1]));
        }
        $state->data['pendingRemoval'] = true;

        [$rx, $ry] = GameRules::POINTS[17];
        $this->game->applyMove($state, 'p0', ['remove' => MoveMap::cellKey($rx, $ry)]);

        // p1 now has only 1 piece (< 3) -> p0 wins
        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
    }
}
