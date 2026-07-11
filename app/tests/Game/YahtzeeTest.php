<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Games\Yahtzee\GameDefinition;
use App\Game\Games\Yahtzee\GameRenderer;
use App\Game\Games\Yahtzee\GameRules;

final class YahtzeeTest extends GameTestCase
{
    private GameRules $rules;
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->game = new GameDefinition($this->rules, new GameRenderer($this->rules));
    }

    public function testScoring(): void
    {
        self::assertSame(9, $this->rules->score('threes', [3, 3, 3, 1, 2]));
        self::assertSame(0, $this->rules->score('sixes', [1, 2, 3, 4, 5]));
        self::assertSame(13, $this->rules->score('three_of_a_kind', [2, 2, 2, 3, 4]));
        self::assertSame(0, $this->rules->score('four_of_a_kind', [2, 2, 2, 3, 4]));
        self::assertSame(25, $this->rules->score('full_house', [2, 2, 3, 3, 3]));
        self::assertSame(25, $this->rules->score('full_house', [5, 5, 5, 5, 5])); // five of a kind counts
        self::assertSame(30, $this->rules->score('small_straight', [1, 2, 3, 4, 6]));
        self::assertSame(0, $this->rules->score('small_straight', [1, 2, 3, 5, 6]));
        self::assertSame(40, $this->rules->score('large_straight', [2, 3, 4, 5, 6]));
        self::assertSame(50, $this->rules->score('yahtzee', [4, 4, 4, 4, 4]));
        self::assertSame(18, $this->rules->score('chance', [1, 2, 3, 6, 6]));
    }

    public function testTotalsAndBonus(): void
    {
        $card = array_fill_keys(GameRules::allCategories(), 0);
        $card['ones'] = 3;
        $card['twos'] = 6;
        $card['threes'] = 9;
        $card['fours'] = 12;
        $card['fives'] = 15;
        $card['sixes'] = 18;
        $card['chance'] = 20;

        self::assertSame(63, $this->rules->upperSubtotal($card));
        self::assertSame(63 + 20 + GameRules::UPPER_BONUS, $this->rules->total($card));
    }

    private function rolledState(): GameState
    {
        $state = $this->game->createInitialState(self::players(2));
        $state->data['hasRolled'] = true;
        $state->data['rollsLeft'] = 1;
        foreach ([3, 3, 3, 2, 2] as $i => $value) {
            $state->dice[$i]->value = $value;
        }

        return $state;
    }

    public function testScoreCategoryFillsCellAndAdvances(): void
    {
        $state = $this->rolledState();

        $this->game->applyMove($state, 'p0', ['action' => 'score', 'category' => 'full_house']);

        self::assertSame(25, $state->data['scorecards']['p0']['full_house']);
        self::assertSame('p1', $state->currentPlayer()->id);
        self::assertFalse($state->data['hasRolled']);
        self::assertSame($state->data['rollsPerTurn'], $state->data['rollsLeft']);
        foreach ($state->dice as $die) {
            self::assertFalse($die->locked);
        }
    }

    public function testCannotScoreSameCategoryTwice(): void
    {
        $state = $this->rolledState();
        $state->data['scorecards']['p0']['full_house'] = 25;

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p0', ['action' => 'score', 'category' => 'full_house']);
    }

    public function testCannotScoreBeforeRolling(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p0', ['action' => 'score', 'category' => 'chance']);
    }

    public function testGameFinishesWhenAllScorecardsComplete(): void
    {
        $state = $this->rolledState();

        // everything filled except p0's full house
        foreach ($state->players as $player) {
            foreach (GameRules::allCategories() as $category) {
                $state->data['scorecards'][$player->id][$category] = 5;
            }
        }
        $state->data['scorecards']['p0']['full_house'] = null;

        $this->game->applyMove($state, 'p0', ['action' => 'score', 'category' => 'full_house']);

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertNotNull($state->winnerId);
    }

    public function testToggleLockRequiresRoll(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p0', ['action' => 'toggle', 'die' => 0]);
    }
}
