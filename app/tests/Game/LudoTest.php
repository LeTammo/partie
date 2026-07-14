<?php

declare(strict_types=1);

namespace App\Game\Games\Ludo;

// Deterministic dice rolls for tests: an unqualified call resolves against
// the caller's own namespace first, so this shadows the global random_int()
// only for GameDefinition::roll(). Falls back to real randomness once the
// queue is empty, so tests that don't care about the roll are unaffected.
function random_int(int $min, int $max): int
{
    return \App\Tests\Game\LudoTest::nextRoll() ?? \random_int($min, $max);
}

namespace App\Tests\Game;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameStatus;
use App\Game\Games\Ludo\GameDefinition;
use App\Game\Games\Ludo\GameRenderer;
use App\Game\Games\Ludo\GameRules;
use App\Game\Games\Ludo\Options;

final class LudoTest extends GameTestCase
{
    private GameRules $rules;
    private GameDefinition $game;

    /** @var list<int> */
    private static array $queuedRolls = [];

    protected function setUp(): void
    {
        $this->rules = new GameRules();
        $this->game = new GameDefinition($this->rules, new GameRenderer($this->rules));
        self::$queuedRolls = [];
    }

    public static function nextRoll(): ?int
    {
        return [] !== self::$queuedRolls ? array_shift(self::$queuedRolls) : null;
    }

    /**
     * @param list<int> $rolls
     */
    private static function queueRolls(array $rolls): void
    {
        self::$queuedRolls = $rolls;
    }

    /**
     * @param list<int>            $p0
     * @param list<int>            $p1
     * @param array<string, mixed> $settings
     */
    private function state(array $p0, array $p1, ?int $roll = null, array $settings = []): \App\Game\Core\Model\GameState
    {
        $state = $this->game->createInitialState(self::players(2), $settings);
        $state->data['pawns']['p0'] = $p0;
        $state->data['pawns']['p1'] = $p1;
        $state->data['roll'] = $roll;

        return $state;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function options(array $overrides = []): Options
    {
        return new Options(
            startOneReleased: $overrides['startOneReleased'] ?? true,
            enforceStartClearingWhilePawnInBase: $overrides['enforceStartClearingWhilePawnInBase'] ?? true,
            allowGoalStretchOvertaking: $overrides['allowGoalStretchOvertaking'] ?? false,
            threeSixesPenalty: $overrides['threeSixesPenalty'] ?? true,
            rerollRule: $overrides['rerollRule'] ?? Options::REROLL_NO_LEGAL_MOVE,
        );
    }

    public function testInitialStateStartsOnePawnReleased(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        // one pawn already on the board (start square), the other three in base
        self::assertSame([0, -1, -1, -1], $state->data['pawns']['p0']);
        self::assertSame([0, -1, -1, -1], $state->data['pawns']['p1']);
        self::assertNull($state->data['roll']);
        self::assertSame(0, $state->data['rollSeq']);
    }

    public function testStartOneReleasedCanBeDisabled(): void
    {
        $state = $this->game->createInitialState(self::players(2), ['startOneReleased' => false]);

        self::assertSame([-1, -1, -1, -1], $state->data['pawns']['p0']);
    }

    public function testNoLegalMoveRuleForcesAGoalStretchMoveWhenOneExists(): void
    {
        // 3 pawns still in base, 1 pawn in the goal stretch at progress 41 (needs +1 or +2 to finish).
        // Default reroll rule is "no_legal_move": ANY pawn having a legal move - even one already
        // safe in the goal stretch - forces this roll to stand; no retries.
        $state = $this->state([-1, -1, -1, 41], [-1, -1, -1, -1]);
        self::queueRolls([1]);

        $this->game->applyMove($state, 'p0', ['action' => 'roll']);

        self::assertSame(1, $state->data['roll']);
        self::assertSame('p0', $state->currentPlayer()->id);
        self::assertSame(0, $state->data['rollAttempts']);
    }

    public function testWhetherARollForcesAMoveNeverDependsOnTheRerollRule(): void
    {
        // Same board and roll as above, but with the "no_open_field" reroll rule. Which rule is
        // active never changes whether a given roll forces a move - that check is always the same,
        // unfiltered hasAnyLegalMove(). The reroll rule only ever affects how many ATTEMPTS a dead
        // roll gets (see the tests below), never whether an actually-usable roll must be played.
        $state = $this->state([-1, -1, -1, 41], [-1, -1, -1, -1], settings: ['rerollRule' => Options::REROLL_NO_OPEN_FIELD]);
        self::queueRolls([1]);

        $this->game->applyMove($state, 'p0', ['action' => 'roll']);

        self::assertSame(1, $state->data['roll'], 'a legal move exists for this roll, so it still stands');
        self::assertSame('p0', $state->currentPlayer()->id);
    }

    public function testNoLegalMoveRuleGrantsNoRetryWhenATheoreticalMoveExistsElsewhere(): void
    {
        // 3 pawns in base, 1 pawn in the goal stretch 2 steps from finishing (progress 41: a roll of
        // 1 or 2 would move it). A roll of 4 is a dead roll - no pawn can use it. Under "no_legal_move"
        // (the default), the number of allowed attempts is decided BEFORE looking at the actual roll:
        // since some die value (1 or 2) would theoretically move the on-board pawn, only one attempt
        // is granted at all - so this single dead roll ends the turn immediately, no retry.
        $state = $this->state([-1, -1, -1, 41], [-1, -1, -1, -1]);
        self::queueRolls([4]);

        $this->game->applyMove($state, 'p0', ['action' => 'roll']);

        self::assertNull($state->data['roll']);
        self::assertSame(0, $state->data['rollAttempts']);
        self::assertSame('p1', $state->currentPlayer()->id, 'a theoretical move exists elsewhere - only one attempt, turn passes');
    }

    public function testNoOpenFieldRuleRetriesWhenNoPawnIsOnTheOpenRing(): void
    {
        // Same board and dead roll (4) as above, but with "no_open_field": the pawn at progress 41
        // is in the goal stretch, not the open ring, so it doesn't count - with no pawn on the open
        // ring at all, the player gets up to three attempts.
        $state = $this->state([-1, -1, -1, 41], [-1, -1, -1, -1], settings: ['rerollRule' => Options::REROLL_NO_OPEN_FIELD]);
        self::queueRolls([4]);

        $this->game->applyMove($state, 'p0', ['action' => 'roll']);

        self::assertNull($state->data['roll']);
        self::assertSame(1, $state->data['rollAttempts']);
        self::assertSame('p0', $state->currentPlayer()->id, 'no pawn on the open ring - retry offered');
    }

    public function testNoOpenFieldRuleGrantsNoRetryWhileAPawnSitsOnTheOpenRing(): void
    {
        // pawn0 near the end of the ring (37), pawn1 on the first goal slot (40) blocking pawn0's
        // target, pawn2 already finished (43) blocking pawn1's target, pawn3 in base. A roll of 3
        // is dead for everyone: pawn0->40 and pawn1->43 are both blocked, pawn2 is done, and pawn3
        // needs a six. Since pawn0 sits on the open ring, "no_open_field" caps attempts at one.
        $state = $this->state([37, 40, 43, -1], [-1, -1, -1, -1], settings: ['rerollRule' => Options::REROLL_NO_OPEN_FIELD]);
        self::queueRolls([3]);

        $this->game->applyMove($state, 'p0', ['action' => 'roll']);

        self::assertNull($state->data['roll']);
        self::assertSame(0, $state->data['rollAttempts']);
        self::assertSame('p1', $state->currentPlayer()->id, 'a pawn sits on the open ring - only one attempt, turn passes');
    }

    public function testRetriesUpToThreeTimesThenTurnPasses(): void
    {
        // All four pawns still in base (startOneReleased disabled): every queued roll (3, 4, 5)
        // fails to roll the six needed to release a pawn - no legal move at all - so the player
        // gets up to three tries before the turn passes.
        $state = $this->state([-1, -1, -1, -1], [-1, -1, -1, -1], settings: ['startOneReleased' => false]);
        self::queueRolls([3, 4, 5]);

        $this->game->applyMove($state, 'p0', ['action' => 'roll']);
        self::assertNull($state->data['roll']);
        self::assertSame(1, $state->data['rollAttempts']);
        self::assertSame('p0', $state->currentPlayer()->id);

        $this->game->applyMove($state, 'p0', ['action' => 'roll']);
        self::assertNull($state->data['roll']);
        self::assertSame(2, $state->data['rollAttempts']);
        self::assertSame('p0', $state->currentPlayer()->id);

        $this->game->applyMove($state, 'p0', ['action' => 'roll']);
        self::assertNull($state->data['roll']);
        self::assertSame(0, $state->data['rollAttempts'], 'attempts reset once the turn passes');
        self::assertSame('p1', $state->currentPlayer()->id, 'three failed attempts - turn passes');
    }

    public function testRollIncrementsRollSeqEveryTime(): void
    {
        $state = $this->game->createInitialState(self::players(2));

        $this->game->applyMove($state, 'p0', ['action' => 'roll']);

        self::assertSame(1, $state->data['rollSeq']);
    }

    public function testRingGeometry(): void
    {
        self::assertSame(0, $this->rules->startIndex(0));
        self::assertSame(10, $this->rules->startIndex(1));
        self::assertSame(5, $this->rules->ringIndexFor(0, 5));
        self::assertSame(3, $this->rules->ringIndexFor(1, 33)); // wraps around
        self::assertNull($this->rules->ringIndexFor(0, GameRules::RING_LENGTH)); // goal stretch, not on ring
    }

    public function testReleaseRequiresASix(): void
    {
        $seats = ['p0' => 0, 'p1' => 1];
        $pawns = ['p0' => [-1, -1, -1, -1], 'p1' => [-1, -1, -1, -1]];

        self::assertSame([], $this->rules->legalMoves($pawns, $seats, 'p0', 3, $this->options()));
        self::assertSame([0, 1, 2, 3], $this->rules->legalMoves($pawns, $seats, 'p0', 6, $this->options()));
    }

    public function testOwnPawnBlocksStartSquare(): void
    {
        $seats = ['p0' => 0, 'p1' => 1];
        $pawns = ['p0' => [0, -1, -1, -1], 'p1' => [-1, -1, -1, -1]];

        // pawn 0 sits on p0's start square (progress 0), so no release - but pawn 0 itself can move
        self::assertSame([0], $this->rules->legalMoves($pawns, $seats, 'p0', 6, $this->options()));
    }

    public function testOwnPawnDoesNotBlockStartSquareWhenSettingDisabled(): void
    {
        $seats = ['p0' => 0, 'p1' => 1];
        $pawns = ['p0' => [0, -1, -1, -1], 'p1' => [-1, -1, -1, -1]];

        $legal = $this->rules->legalMoves($pawns, $seats, 'p0', 6, $this->options(['enforceStartClearingWhilePawnInBase' => false]));

        self::assertSame([0, 1, 2, 3], $legal, 'the pawn already on the start square and every base pawn can all move');
    }

    public function testReleaseAllowedOnceOwnPawnHasMovedOffStartSquare(): void
    {
        $seats = ['p0' => 0, 'p1' => 1];
        $pawns = ['p0' => [5, -1, -1, -1], 'p1' => [-1, -1, -1, -1]];

        $legal = $this->rules->legalMoves($pawns, $seats, 'p0', 6, $this->options());

        self::assertSame([0, 1, 2, 3], $legal, 'the start square is empty now, so every pawn can move');
    }

    public function testReleaseOntoAnOpponentOnTheStartSquareIsAllowed(): void
    {
        $seats = ['p0' => 0, 'p1' => 1];
        // p1 (seat 1, start index 10) sits at progress 30, which is ring index (10+30)%40 = 0 - p0's start
        $pawns = ['p0' => [-1, -1, -1, -1], 'p1' => [30, -1, -1, -1]];

        $legal = $this->rules->legalMoves($pawns, $seats, 'p0', 6, $this->options());

        self::assertSame([0, 1, 2, 3], $legal, 'blocking only applies to your OWN pawn - an opponent there can be captured');
    }

    public function testCannotOvershootFinish(): void
    {
        $seats = ['p0' => 0, 'p1' => 1];
        $pawns = ['p0' => [GameRules::FINISH_PROGRESS - 1, -1, -1, -1], 'p1' => [-1, -1, -1, -1]];

        self::assertSame([0], $this->rules->legalMoves($pawns, $seats, 'p0', 1, $this->options()));
        self::assertSame([], $this->rules->legalMoves($pawns, $seats, 'p0', 2, $this->options()));
    }

    public function testMoveCapturesOpponent(): void
    {
        // p1 (seat 1) at progress 0 = ring index 10; p0 at progress 6 moving 4 lands on ring index 10
        $state = $this->state([6, -1, -1, -1], [0, -1, -1, -1], roll: 4);

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'ring:6']);

        self::assertSame(10, $state->data['pawns']['p0'][0]);
        self::assertSame(-1, $state->data['pawns']['p1'][0]); // captured back to base
    }

    public function testMoveWithoutRollIsRejected(): void
    {
        $state = $this->state([0, -1, -1, -1], [-1, -1, -1, -1], roll: null);

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'ring:0']);
    }

    public function testSixGrantsAnotherTurn(): void
    {
        $state = $this->state([0, -1, -1, -1], [-1, -1, -1, -1], roll: 6);

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'ring:0']);

        self::assertSame('p0', $state->currentPlayer()->id);
        self::assertNull($state->data['roll']);
    }

    public function testFinishingLastPawnWinsGame(): void
    {
        // goal-stretch slots are exclusive: three pawns parked on 43/42/41, the last one enters slot 40
        $state = $this->state([43, 42, 41, 38], [-1, -1, -1, -1], roll: 2);

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'ring:38']);

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
    }

    public function testGoalStretchSlotCollisionIsIllegal(): void
    {
        $seats = ['p0' => 0, 'p1' => 1];
        $lane = GameRules::RING_LENGTH; // first goal-stretch slot
        $pawns = ['p0' => [$lane, GameRules::RING_LENGTH - 1, -1, -1], 'p1' => [-1, -1, -1, -1]];

        // pawn 1 would land on the occupied goal slot with a roll of 1
        $legal = $this->rules->legalMoves($pawns, $seats, 'p0', 1, $this->options());
        self::assertNotContains(1, $legal);
    }

    public function testGoalStretchOvertakingDisallowedByDefault(): void
    {
        $seats = ['p0' => 0, 'p1' => 1];
        // pawn 0 sits at the first goal slot; pawn 1 enters the stretch and would jump past it
        $pawns = ['p0' => [GameRules::RING_LENGTH, GameRules::RING_LENGTH - 3, -1, -1], 'p1' => [-1, -1, -1, -1]];

        $legal = $this->rules->legalMoves($pawns, $seats, 'p0', 5, $this->options());

        self::assertNotContains(1, $legal, 'pawn 1 would have to jump over pawn 0 sitting in the stretch');
    }

    public function testGoalStretchOvertakingAllowedWhenSettingEnabled(): void
    {
        $seats = ['p0' => 0, 'p1' => 1];
        $pawns = ['p0' => [GameRules::RING_LENGTH, GameRules::RING_LENGTH - 3, -1, -1], 'p1' => [-1, -1, -1, -1]];

        $legal = $this->rules->legalMoves($pawns, $seats, 'p0', 5, $this->options(['allowGoalStretchOvertaking' => true]));

        self::assertContains(1, $legal);
    }

    public function testThreeSixesInARowSendsAChosenPawnBackToBase(): void
    {
        $state = $this->state([0, 15, -1, -1], [-1, -1, -1, -1]);
        $state->data['sixStreak'] = 2; // two sixes already rolled this turn
        self::queueRolls([6]);

        $this->game->applyMove($state, 'p0', ['action' => 'roll']);

        self::assertNull($state->data['roll'], 'the third six cannot be used to move');
        self::assertTrue($state->data['awaitingBanish']);
        self::assertSame(0, $state->data['sixStreak']);
        self::assertSame('p0', $state->currentPlayer()->id, 'still p0 - they must choose a pawn first');

        $this->game->applyMove($state, 'p0', ['action' => 'banish', 'banish' => 'ring:15']);

        self::assertSame(-1, $state->data['pawns']['p0'][1]);
        self::assertFalse($state->data['awaitingBanish']);
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testThreeSixesPenaltyCanBeDisabled(): void
    {
        $state = $this->state([0, -1, -1, -1], [-1, -1, -1, -1], settings: ['threeSixesPenalty' => false]);
        $state->data['sixStreak'] = 2;
        self::queueRolls([6]);

        $this->game->applyMove($state, 'p0', ['action' => 'roll']);

        self::assertSame(6, $state->data['roll']);
        self::assertFalse($state->data['awaitingBanish']);
        self::assertSame(3, $state->data['sixStreak']);
    }

    public function testThreeSixesWithNoPawnOutSkipsBanishAndPassesTurn(): void
    {
        // Edge case exercised directly via state, not reachable through three real rolls: with
        // nothing on the board to send back, the penalty just costs the turn instead.
        $state = $this->state([-1, -1, -1, -1], [-1, -1, -1, -1], settings: ['startOneReleased' => false]);
        $state->data['sixStreak'] = 2;
        self::queueRolls([6]);

        $this->game->applyMove($state, 'p0', ['action' => 'roll']);

        self::assertNull($state->data['roll']);
        self::assertFalse($state->data['awaitingBanish']);
        self::assertSame('p1', $state->currentPlayer()->id);
    }

    public function testBanishRejectsAPawnStillInBase(): void
    {
        $state = $this->state([0, -1, -1, -1], [-1, -1, -1, -1]);
        $state->data['sixStreak'] = 2;
        self::queueRolls([6]);
        $this->game->applyMove($state, 'p0', ['action' => 'roll']);

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p0', ['action' => 'banish', 'banish' => 'base:0:1']);
    }
}
