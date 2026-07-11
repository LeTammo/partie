<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Card\Rank;
use App\Game\Core\Card\Suit;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Games\Solitaire\GameDefinition;
use App\Game\Games\Solitaire\GameRenderer;
use App\Game\Games\Solitaire\GameRules;

final class SolitaireTest extends GameTestCase
{
    private GameRules $rules;
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->game = new GameDefinition($this->rules, new GameRenderer($this->rules));
    }

    private function emptyState(): GameState
    {
        $state = $this->game->createInitialState(self::players(1));
        foreach (array_keys($state->data['tableau']) as $col) {
            $state->data['tableau'][$col] = [];
        }
        foreach (Suit::cases() as $suit) {
            $state->data['foundations'][$suit->value] = [];
        }
        $state->data['stock'] = [];
        $state->data['waste'] = [];

        return $state;
    }

    public function testInitialDeal(): void
    {
        $state = $this->game->createInitialState(self::players(1));

        self::assertCount(GameRules::COLUMNS, $state->data['tableau']);
        foreach ($state->data['tableau'] as $col => $pile) {
            self::assertCount($col + 1, $pile);
            foreach ($pile as $i => $slot) {
                self::assertSame($i === $col, $slot['faceUp']);
            }
        }
        self::assertCount(24, $state->data['stock']);
        self::assertSame([], $state->data['waste']);
    }

    public function testStackingRules(): void
    {
        $redFive = self::card(Suit::Hearts, Rank::Five);
        $blackSix = self::card(Suit::Spades, Rank::Six);
        $redSix = self::card(Suit::Diamonds, Rank::Six);

        self::assertTrue($this->rules->canStackTableau($redFive, $blackSix));
        self::assertFalse($this->rules->canStackTableau($redFive, $redSix));
        self::assertFalse($this->rules->canStackTableau($blackSix, $redFive));

        // empty tableau column only takes a king
        self::assertTrue($this->rules->canDropOnTableau(self::card(Suit::Clubs, Rank::King), []));
        self::assertFalse($this->rules->canDropOnTableau($redFive, []));

        // foundation: ace first, then same suit ascending
        self::assertTrue($this->rules->canDropOnFoundation(self::card(Suit::Hearts, Rank::Ace), []));
        self::assertFalse($this->rules->canDropOnFoundation($redFive, []));
        self::assertTrue($this->rules->canDropOnFoundation(
            self::card(Suit::Hearts, Rank::Two),
            [self::card(Suit::Hearts, Rank::Ace)],
        ));
    }

    public function testDrawMovesStockToWaste(): void
    {
        $state = $this->emptyState();
        $state->data['stock'] = [self::card(Suit::Hearts, Rank::Two), self::card(Suit::Clubs, Rank::Three)];

        $this->game->applyMove($state, 'p0', ['action' => 'draw']);

        self::assertCount(1, $state->data['stock']);
        self::assertCount(1, $state->data['waste']);
        self::assertTrue($state->data['waste'][0]->is(Suit::Clubs, Rank::Three));
    }

    public function testEmptyStockRecyclesWaste(): void
    {
        $state = $this->emptyState();
        $state->data['waste'] = [self::card(Suit::Hearts, Rank::Two), self::card(Suit::Clubs, Rank::Three)];

        $this->game->applyMove($state, 'p0', ['action' => 'draw']);

        self::assertSame([], $state->data['waste']);
        self::assertCount(2, $state->data['stock']);
        // recycle reverses so the first-drawn card comes out first again
        self::assertTrue(end($state->data['stock'])->is(Suit::Hearts, Rank::Two));
    }

    public function testMoveRunBetweenTableauColumns(): void
    {
        $state = $this->emptyState();
        $state->data['tableau'][0] = [
            ['card' => self::card(Suit::Spades, Rank::Ten), 'faceUp' => false],
            ['card' => self::card(Suit::Hearts, Rank::Six), 'faceUp' => true],
            ['card' => self::card(Suit::Clubs, Rank::Five), 'faceUp' => true],
        ];
        $state->data['tableau'][1] = [
            ['card' => self::card(Suit::Spades, Rank::Seven), 'faceUp' => true],
        ];

        // move the 6♥-5♣ run onto the 7♠
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'tableau:0:1', 'to' => 'tableau:1']);

        self::assertCount(1, $state->data['tableau'][0]);
        self::assertTrue($state->data['tableau'][0][0]['faceUp']); // exposed card flipped
        self::assertCount(3, $state->data['tableau'][1]);
    }

    public function testIllegalTableauMoveRejected(): void
    {
        $state = $this->emptyState();
        $state->data['tableau'][0] = [['card' => self::card(Suit::Hearts, Rank::Six), 'faceUp' => true]];
        $state->data['tableau'][1] = [['card' => self::card(Suit::Diamonds, Rank::Seven), 'faceUp' => true]];

        $this->expectException(InvalidMoveException::class);
        // red on red
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'tableau:0:0', 'to' => 'tableau:1']);
    }

    public function testWasteToFoundationAndWin(): void
    {
        $state = $this->emptyState();
        foreach (Suit::cases() as $suit) {
            // foundation order: ace, 2 .. king
            $pile = [self::card($suit, Rank::Ace)];
            foreach (Rank::cases() as $rank) {
                if (Rank::Ace !== $rank) {
                    $pile[] = self::card($suit, $rank);
                }
            }
            if (Suit::Hearts === $suit) {
                array_pop($pile); // hearts still waits for its king
            }
            $state->data['foundations'][$suit->value] = $pile;
        }
        $state->data['waste'] = [self::card(Suit::Hearts, Rank::King)];

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'waste', 'to' => 'foundation:hearts']);

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
    }
}
