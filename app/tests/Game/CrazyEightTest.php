<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Card\CustomCard;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Games\CrazyEight\GameDefinition;
use App\Game\Games\CrazyEight\GameRenderer;
use App\Game\Games\CrazyEight\GameRules;

final class CrazyEightTest extends GameTestCase
{
    private GameRules $rules;
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->game = new GameDefinition($this->rules, new GameRenderer($this->rules));
    }

    private function state(int $players, array $hands, CustomCard $top, array $settings = []): GameState
    {
        $state = $this->game->createInitialState(self::players($players), $settings);
        foreach ($hands as $i => $hand) {
            $state->table->hand('p'.$i)->items = $hand;
        }
        $state->table->zone('discard')->items = [$top];
        $state->table->zone('stock')->items = [
            new CustomCard('red', '1'),
            new CustomCard('blue', '2'),
            new CustomCard('green', '3'),
            new CustomCard('yellow', '4'),
        ];

        return $state;
    }

    public function testInitialStateDealsSevenPerPlayer(): void
    {
        $state = $this->game->createInitialState(self::players(3));

        foreach ($state->players as $player) {
            self::assertCount(7, $state->table->hand($player->id)->items);
        }
        self::assertCount(1, $state->table->zone('discard')->items);
        // 108 total - 21 dealt - 1 discard = 86
        self::assertCount(86, $state->table->zone('stock')->items);
    }

    public function testPlayableMatchesColorOrValue(): void
    {
        $top = new CustomCard('red', '5');

        self::assertTrue($this->rules->playable(new CustomCard('red', '9'), $top, null, 0, null, true));
        self::assertTrue($this->rules->playable(new CustomCard('blue', '5'), $top, null, 0, null, true));
        self::assertFalse($this->rules->playable(new CustomCard('blue', '9'), $top, null, 0, null, true));
        self::assertTrue($this->rules->playable(new CustomCard('wild', GameRules::WILD), $top, null, 0, null, true));
    }

    public function testWishedColorRestrictsPlays(): void
    {
        $top = new CustomCard('wild', GameRules::WILD);

        self::assertTrue($this->rules->playable(new CustomCard('blue', '3'), $top, 'blue', 0, null, true));
        self::assertFalse($this->rules->playable(new CustomCard('red', '3'), $top, 'blue', 0, null, true));
    }

    public function testDrawTwoAddsPendingDraw(): void
    {
        $state = $this->state(2, [
            [new CustomCard('red', GameRules::DRAW_TWO), new CustomCard('blue', '1')],
            [new CustomCard('green', '9')],
        ], new CustomCard('red', '9'));

        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);

        self::assertSame(2, $state->data['pendingDraw']);
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testPendingDrawIsTakenAllAtOnce(): void
    {
        $state = $this->state(2, [
            [new CustomCard('blue', '1')],
            [new CustomCard('green', '9')],
        ], new CustomCard('red', GameRules::DRAW_TWO));
        $state->data['pendingDraw'] = 2;
        $state->data['pendingDrawValue'] = GameRules::DRAW_TWO;
        $state->currentTurnIndex = 1;

        $this->game->applyMove($state, 'p1', ['action' => 'draw']);

        self::assertSame(0, $state->data['pendingDraw']);
        self::assertCount(3, $state->table->hand('p1')->items);
        self::assertSame('p0', $state->currentPlayer()->id);
    }

    public function testSkipSkipsNextPlayerInThreePlayerGame(): void
    {
        $state = $this->state(3, [
            [new CustomCard('red', GameRules::SKIP), new CustomCard('blue', '1')],
            [new CustomCard('green', '9')],
            [new CustomCard('yellow', '9')],
        ], new CustomCard('red', '9'));

        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);

        // p1 is skipped, turn goes to p2
        self::assertSame('p2', $state->currentPlayer()->id);
    }

    public function testReverseActsAsSkipWithTwoPlayers(): void
    {
        $state = $this->state(2, [
            [new CustomCard('red', GameRules::REVERSE), new CustomCard('blue', '1')],
            [new CustomCard('green', '9')],
        ], new CustomCard('red', '9'));

        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);

        self::assertSame('p0', $state->currentPlayer()->id);
        self::assertSame(-1, $state->data['direction']);
    }

    public function testReverseFlipsOrderWithThreePlayers(): void
    {
        $state = $this->state(3, [
            [new CustomCard('red', GameRules::REVERSE), new CustomCard('blue', '1')],
            [new CustomCard('green', '9')],
            [new CustomCard('yellow', '9')],
        ], new CustomCard('red', '9'));

        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);

        // direction flips to -1, so turn goes to p2 (seat before p0), not p1
        self::assertSame('p2', $state->currentPlayer()->id);
        self::assertSame(-1, $state->data['direction']);
    }

    public function testWildRequiresWish(): void
    {
        $state = $this->state(2, [
            [new CustomCard('wild', GameRules::WILD), new CustomCard('blue', '1')],
            [new CustomCard('green', '9')],
        ], new CustomCard('red', '9'));

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);
            self::fail('expected InvalidMoveException');
        } catch (InvalidMoveException) {
        }

        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0, 'wish' => 'blue']);
        self::assertSame('blue', $state->data['wishedColor']);
    }

    public function testDrawThenPass(): void
    {
        $state = $this->state(2, [
            [new CustomCard('blue', '1')],
            [new CustomCard('green', '9')],
        ], new CustomCard('red', '9'));

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'pass']);
            self::fail('cannot pass before drawing');
        } catch (InvalidMoveException) {
        }

        $this->game->applyMove($state, 'p0', ['action' => 'draw']);
        self::assertCount(2, $state->table->hand('p0')->items);

        $this->game->applyMove($state, 'p0', ['action' => 'pass']);
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testPlayingLastCardWins(): void
    {
        $state = $this->state(2, [
            [new CustomCard('red', '3')],
            [new CustomCard('green', '9')],
        ], new CustomCard('red', '9'));

        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
    }
}
