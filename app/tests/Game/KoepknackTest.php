<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Card\Rank;
use App\Game\Core\Card\Suit;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Games\Koepknack\GameDefinition;
use App\Game\Games\Koepknack\GameRenderer;
use App\Game\Games\Koepknack\GameRules;

final class KoepknackTest extends GameTestCase
{
    private GameRules $rules;
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->game = new GameDefinition($this->rules, new GameRenderer($this->rules));
    }

    private function state(array $handP0, array $handP1, array $middle): GameState
    {
        $state = $this->game->createInitialState(self::players(2), ['roundsTotal' => 2]);
        $state->table->hand('p0')->items = $handP0;
        $state->table->hand('p1')->items = $handP1;
        $state->table->zone('middle')->items = $middle;

        return $state;
    }

    public function testHandValues(): void
    {
        // same-suit sum
        self::assertSame(21.0, $this->rules->value([
            self::card(Suit::Hearts, Rank::Ace),
            self::card(Suit::Hearts, Rank::Ten),
            self::card(Suit::Clubs, Rank::Nine),
        ]));

        // three of a kind = 30.5
        self::assertSame(30.5, $this->rules->value([
            self::card(Suit::Hearts, Rank::Nine),
            self::card(Suit::Clubs, Rank::Nine),
            self::card(Suit::Spades, Rank::Nine),
        ]));

        // three aces = fire
        $fire = [
            self::card(Suit::Hearts, Rank::Ace),
            self::card(Suit::Clubs, Rank::Ace),
            self::card(Suit::Spades, Rank::Ace),
        ];
        self::assertSame(GameRules::FIRE, $this->rules->value($fire));
        self::assertTrue($this->rules->isFire($fire));
    }

    public function testSwapExchangesCards(): void
    {
        $state = $this->state(
            [self::card(Suit::Hearts, Rank::Seven), self::card(Suit::Clubs, Rank::Eight), self::card(Suit::Spades, Rank::Nine)],
            [self::card(Suit::Hearts, Rank::Eight), self::card(Suit::Clubs, Rank::Nine), self::card(Suit::Spades, Rank::Ten)],
            [self::card(Suit::Diamonds, Rank::King), self::card(Suit::Diamonds, Rank::Nine), self::card(Suit::Clubs, Rank::Ten)],
        );

        $this->game->applyMove($state, 'p0', ['action' => 'swap', 'hand' => 0, 'middle' => 0]);

        self::assertTrue($state->table->hand('p0')->items[0]->is(Suit::Diamonds, Rank::King));
        self::assertTrue($state->table->zone('middle')->items[0]->is(Suit::Hearts, Rank::Seven));
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testReachingKnackEndsRound(): void
    {
        $state = $this->state(
            [self::card(Suit::Hearts, Rank::Ace), self::card(Suit::Hearts, Rank::Ten), self::card(Suit::Clubs, Rank::Nine)],
            [self::card(Suit::Hearts, Rank::Eight), self::card(Suit::Clubs, Rank::Nine), self::card(Suit::Spades, Rank::Ten)],
            [self::card(Suit::Hearts, Rank::King), self::card(Suit::Diamonds, Rank::Nine), self::card(Suit::Clubs, Rank::Ten)],
        );

        // swap 9♣ for K♥: hand becomes A♥ 10♥ K♥ = 31 = Knack
        $this->game->applyMove($state, 'p0', ['action' => 'swap', 'hand' => 2, 'middle' => 0]);

        self::assertSame('roundend', $state->data['phase']);
        self::assertSame(1, $state->data['points']['p0']);
    }

    public function testCloseGivesEveryoneOneMoreTurn(): void
    {
        $state = $this->state(
            [self::card(Suit::Hearts, Rank::Seven), self::card(Suit::Clubs, Rank::Eight), self::card(Suit::Spades, Rank::Nine)],
            [self::card(Suit::Hearts, Rank::Ace), self::card(Suit::Hearts, Rank::Ten), self::card(Suit::Hearts, Rank::King)],
            [self::card(Suit::Diamonds, Rank::King), self::card(Suit::Diamonds, Rank::Nine), self::card(Suit::Clubs, Rank::Ten)],
        );

        $this->game->applyMove($state, 'p0', ['action' => 'close']);
        self::assertSame('p1', $state->currentPlayer()->id);
        self::assertSame('playing', $state->data['phase']);

        // p1 passes; the turn returns to the closer -> round ends, p1 wins with 31
        $this->game->applyMove($state, 'p1', ['action' => 'pass']);
        self::assertSame('roundend', $state->data['phase']);
        self::assertSame(1, $state->data['points']['p1']);
    }

    public function testCannotSwapAfterRoundEnd(): void
    {
        $state = $this->state(
            [self::card(Suit::Hearts, Rank::Seven), self::card(Suit::Clubs, Rank::Eight), self::card(Suit::Spades, Rank::Nine)],
            [self::card(Suit::Hearts, Rank::Eight), self::card(Suit::Clubs, Rank::Nine), self::card(Suit::Spades, Rank::Ten)],
            [self::card(Suit::Diamonds, Rank::King), self::card(Suit::Diamonds, Rank::Nine), self::card(Suit::Clubs, Rank::Ten)],
        );
        $state->data['phase'] = 'roundend';

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p0', ['action' => 'swap', 'hand' => 0, 'middle' => 0]);
    }

    public function testNewRoundRotatesStarter(): void
    {
        $state = $this->state(
            [self::card(Suit::Hearts, Rank::Seven), self::card(Suit::Clubs, Rank::Eight), self::card(Suit::Spades, Rank::Nine)],
            [self::card(Suit::Hearts, Rank::Eight), self::card(Suit::Clubs, Rank::Nine), self::card(Suit::Spades, Rank::Ten)],
            [self::card(Suit::Diamonds, Rank::King), self::card(Suit::Diamonds, Rank::Nine), self::card(Suit::Clubs, Rank::Ten)],
        );
        $state->data['phase'] = 'roundend';

        $this->game->applyMove($state, 'p1', ['action' => 'newround']);

        self::assertSame('playing', $state->data['phase']);
        self::assertSame(2, $state->data['round']);
        self::assertSame('p1', $state->currentPlayer()->id);
    }
}
