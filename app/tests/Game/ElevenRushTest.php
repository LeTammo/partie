<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Card\CustomCard;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Games\ElevenRush\GameDefinition;
use App\Game\Games\ElevenRush\GameRenderer;
use App\Game\Games\ElevenRush\GameRules;

final class ElevenRushTest extends GameTestCase
{
    private GameRules $rules;
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->game = new GameDefinition($this->rules, new GameRenderer($this->rules));
    }

    /**
     * @param list<CustomCard>                $handP0
     * @param list<CustomCard>                $handP1
     * @param array{color: string, value: string}|null $startingElf
     */
    private function state(array $handP0, array $handP1, ?array $startingElf = null): GameState
    {
        $state = $this->game->createInitialState(self::players(2));
        $state->table->hand('p0')->items = $handP0;
        $state->table->hand('p1')->items = $handP1;
        $state->table->zone('stock')->items = [new CustomCard('red', '5'), new CustomCard('blue', '17')];
        $state->data['board'] = [
            'red' => ['min' => null, 'max' => null],
            'yellow' => ['min' => null, 'max' => null],
            'green' => ['min' => null, 'max' => null],
            'blue' => ['min' => null, 'max' => null],
        ];
        $state->data['startingElf'] = $startingElf;
        $state->data['cardsPlayedThisTurn'] = 0;
        $state->data['drawCountThisTurn'] = 0;
        $state->currentTurnIndex = 0;

        return $state;
    }

    public function testInitialDealTwoPlayers(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        // 80 cards: 40 stay in stock with 2 players, 20 per hand
        self::assertSame(40, $state->table->zone('stock')->count());
        self::assertSame(20, $state->table->hand('p0')->count());
        self::assertSame(20, $state->table->hand('p1')->count());
        foreach ($state->data['board'] as $range) {
            self::assertNull($range['min']);
            self::assertNull($range['max']);
        }
    }

    public function testPlayableRules(): void
    {
        $board = [
            'red' => ['min' => 10, 'max' => 12],
            'yellow' => ['min' => null, 'max' => null],
            'green' => ['min' => null, 'max' => null],
            'blue' => ['min' => null, 'max' => null],
        ];

        // an 11 is always playable once the opening is done
        self::assertTrue($this->rules->playable(new CustomCard('yellow', '11'), $board, null));
        // adjacent to an open range
        self::assertTrue($this->rules->playable(new CustomCard('red', '9'), $board, null));
        self::assertTrue($this->rules->playable(new CustomCard('red', '13'), $board, null));
        // gaps and closed colors are not playable
        self::assertFalse($this->rules->playable(new CustomCard('red', '8'), $board, null));
        self::assertFalse($this->rules->playable(new CustomCard('green', '12'), $board, null));

        // while the starting elf is pending, only that exact card may open
        $elf = new CustomCard('red', '11');
        self::assertTrue($this->rules->playable(new CustomCard('red', '11'), $board, $elf));
        self::assertFalse($this->rules->playable(new CustomCard('yellow', '11'), $board, $elf));
    }

    public function testStartingElfOpensAndTurnFlow(): void
    {
        $state = $this->state(
            [new CustomCard('red', '11'), new CustomCard('red', '12'), new CustomCard('green', '3')],
            [new CustomCard('blue', '11')],
            startingElf: ['color' => 'red', 'value' => '11'],
        );

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 2]);
            self::fail('only the starting elf may open the game');
        } catch (InvalidMoveException) {
        }

        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);
        self::assertSame(['min' => 11, 'max' => 11], $state->data['board']['red']);
        self::assertNull($state->data['startingElf']);

        // the red 12 extends the range; drawing is now forbidden this turn
        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);
        self::assertSame(['min' => 11, 'max' => 12], $state->data['board']['red']);

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'draw']);
            self::fail('cannot draw after playing');
        } catch (InvalidMoveException) {
        }

        $this->game->applyMove($state, 'p0', ['action' => 'pass']);
        self::assertSame('p1', $state->currentPlayer()->id);
        self::assertSame(0, $state->data['cardsPlayedThisTurn']);
        self::assertSame(0, $state->data['drawCountThisTurn']);
    }

    public function testDrawFlowAndMustAct(): void
    {
        $state = $this->state(
            [new CustomCard('green', '3')],
            [new CustomCard('blue', '11')],
        );

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'pass']);
            self::fail('must play or draw before passing');
        } catch (InvalidMoveException) {
        }

        $this->game->applyMove($state, 'p0', ['action' => 'draw']);
        $this->game->applyMove($state, 'p0', ['action' => 'draw']);
        self::assertCount(3, $state->table->hand('p0')->items);

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'draw']);
            self::fail('stock is empty');
        } catch (InvalidMoveException) {
        }

        $this->game->applyMove($state, 'p0', ['action' => 'pass']);
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testEmptyingHandWins(): void
    {
        $state = $this->state(
            [new CustomCard('red', '12')],
            [new CustomCard('blue', '11')],
        );
        $state->data['board']['red'] = ['min' => 10, 'max' => 11];

        $this->game->applyMove($state, 'p0', ['action' => 'play', 'card' => 0]);

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
    }
}
