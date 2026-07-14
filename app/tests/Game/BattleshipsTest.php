<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Games\Battleships\GameDefinition;
use App\Game\Games\Battleships\GameRenderer;
use App\Game\Games\Battleships\GameRules;

final class BattleshipsTest extends GameTestCase
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

    public function testShapePoolFallsBackToClassicPoolWhenEverythingIsZero(): void
    {
        self::assertSame(['line5', 'line4', 'line3', 'line3', 'line2'], $this->rules->shapePool([]));
    }

    public function testShapePoolUsesGivenCountsInDisplayOrder(): void
    {
        self::assertSame(['line4', 'line4', 'line2'], $this->rules->shapePool(['line4' => 2, 'line2' => 1]));
    }

    public function testOrientationsDedupeFullySymmetricShapes(): void
    {
        self::assertCount(1, $this->rules->orientations('square4'));
        self::assertCount(2, $this->rules->orientations('square6'));
        self::assertCount(2, $this->rules->orientations('line5'));
    }

    public function testOrientationsAreDedupedAndNormalizedForEveryShape(): void
    {
        foreach (array_keys(GameRules::SHAPES) as $shape) {
            $orientations = $this->rules->orientations($shape);

            self::assertNotEmpty($orientations);
            self::assertLessThanOrEqual(8, \count($orientations));

            $signatures = array_map(static function (array $cells): string {
                $keys = array_map(static fn (array $c): string => $c[0].':'.$c[1], $cells);
                sort($keys);

                return implode('|', $keys);
            }, $orientations);
            self::assertSame($signatures, array_unique($signatures), "orientations for '$shape' contain duplicates");

            foreach ($orientations as $cells) {
                self::assertSame(0, min(array_column($cells, 0)), "orientation for '$shape' is not x-normalized");
                self::assertSame(0, min(array_column($cells, 1)), "orientation for '$shape' is not y-normalized");
            }
        }
    }

    public function testShapeCellsTranslatesTheCanonicalShapeByTheAnchor(): void
    {
        self::assertSame([[5, 5], [5, 6], [5, 7], [6, 7]], $this->rules->shapeCells('l', 0, 5, 5));
    }

    public function testInitialStateStartsInPlacingPhase(): void
    {
        $state = $this->state();

        self::assertSame('placing', $state->data['phase']);
        self::assertSame(['line5', 'line4', 'line3', 'line3', 'line2'], $state->data['shapePool']);
        self::assertFalse($state->data['ready']['p0']);
    }

    public function testGridSizeSettingChangesBoardDimensions(): void
    {
        $state = $this->state(['gridWidth' => 8, 'gridHeight' => 12]);

        self::assertSame(8, $state->data['fleets']['p0']->width);
        self::assertSame(12, $state->data['fleets']['p0']->height);
        self::assertSame(8, $state->data['shots']['p0']->width);
        self::assertSame(12, $state->data['shots']['p0']->height);
    }

    public function testOverflowingPoolIsTrimmedToFitTheBoard(): void
    {
        $state = $this->state([
            'gridWidth' => 6, 'gridHeight' => 6, // 36 cells, 70% cap = 25
            'shipsSquare6' => 4, // 24 cells alone
            'shipsLine5' => 4, // would add 20 more if untrimmed
            'shipsLine4' => 0, 'shipsLine3' => 0, 'shipsLine2' => 0,
            'shipsV' => 0, 'shipsS5' => 0, 'shipsL' => 0, 'shipsSquare4' => 0, 'shipsS4' => 0,
        ]);

        $totalCells = array_sum(array_map(
            static fn (string $shape): int => \count(GameRules::SHAPES[$shape]),
            $state->data['shapePool'],
        ));

        self::assertLessThanOrEqual((int) floor(6 * 6 * 0.7), $totalCells);
        self::assertNotEmpty($state->data['shapePool']);
    }

    public function testPlacingOutOfBoundsOrOverlappingFails(): void
    {
        $state = $this->state();

        try {
            // pool[0] is a 5-cell line; horizontal from x=8 runs off the 10-wide board
            $this->game->applyMove($state, 'p0', ['action' => 'place', 'index' => 0, 'x' => 8, 'y' => 0, 'orientation' => 0]);
            self::fail('expected out-of-bounds placement to be rejected');
        } catch (InvalidMoveException) {
            self::assertSame(0, $state->data['placedCount']['p0']);
        }

        $this->game->applyMove($state, 'p0', ['action' => 'place', 'index' => 0, 'x' => 0, 'y' => 0, 'orientation' => 0]); // (0,0)-(4,0)

        try {
            // pool[1] is a 4-cell line; this overlaps the ship just placed
            $this->game->applyMove($state, 'p0', ['action' => 'place', 'index' => 1, 'x' => 2, 'y' => 0, 'orientation' => 0]);
            self::fail('expected overlapping placement to be rejected');
        } catch (InvalidMoveException) {
            self::assertSame(1, $state->data['placedCount']['p0']);
        }
    }

    public function testCannotReuseAPoolIndexAlreadyPlaced(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'place', 'index' => 0, 'x' => 0, 'y' => 0, 'orientation' => 0]);

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p0', ['action' => 'place', 'index' => 0, 'x' => 5, 'y' => 5, 'orientation' => 0]);
    }

    public function testVerticalOrientationPlacesDownward(): void
    {
        $state = $this->state();
        // orientation 1 of a line shape is the 90°-rotated (vertical) form
        $this->game->applyMove($state, 'p0', ['action' => 'place', 'index' => 0, 'x' => 0, 'y' => 0, 'orientation' => 1]);

        $fleet = $state->data['fleets']['p0'];
        self::assertNotNull($fleet->get(0, 4));
        self::assertNull($fleet->get(4, 0));
    }

    public function testRandomizePlacesFullFleetAndMarksReady(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'randomize']);

        self::assertTrue($state->data['ready']['p0']);
        self::assertSame(5, $state->data['placedCount']['p0']);
        self::assertCount(17, $state->data['fleets']['p0']->tokens()); // 5+4+3+3+2 cells
    }

    public function testBattleStartsOnceBothFleetsAreReady(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'randomize']);
        self::assertSame('placing', $state->data['phase']);

        $this->game->applyMove($state, 'p1', ['action' => 'randomize']);
        self::assertSame('battle', $state->data['phase']);
        self::assertSame('p0', $state->currentPlayer()->id);
    }

    public function testFireHitAndMissAlternateTurnsRegardlessByDefault(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'randomize']);
        $this->game->applyMove($state, 'p1', ['action' => 'randomize']);

        $fleet1 = $state->data['fleets']['p1'];
        $emptyCell = null;
        for ($y = 0; $y < 10 && null === $emptyCell; ++$y) {
            for ($x = 0; $x < 10; ++$x) {
                if ($fleet1->isEmpty($x, $y)) {
                    $emptyCell = [$x, $y];
                    break;
                }
            }
        }

        $this->game->applyMove($state, 'p0', ['action' => 'fire', 'x' => $emptyCell[0], 'y' => $emptyCell[1]]);

        self::assertSame('p1', $state->currentPlayer()->id);
        self::assertNotNull($state->data['shots']['p0']->get($emptyCell[0], $emptyCell[1]));
    }

    public function testExtraTurnOnHitKeepsTurnOnHitButNotOnMiss(): void
    {
        $state = $this->state([
            'extraTurnOnHit' => true,
            'shipsLine5' => 0, 'shipsLine4' => 0, 'shipsLine3' => 0, 'shipsLine2' => 2,
        ]);
        $this->game->applyMove($state, 'p0', ['action' => 'randomize']);
        $this->game->applyMove($state, 'p1', ['action' => 'randomize']);

        $fleet1 = $state->data['fleets']['p1'];
        $hitCell = null;
        $missCell = null;
        for ($y = 0; $y < 10 && (null === $hitCell || null === $missCell); ++$y) {
            for ($x = 0; $x < 10; ++$x) {
                if (null === $hitCell && null !== $fleet1->get($x, $y)) {
                    $hitCell = [$x, $y];
                }
                if (null === $missCell && $fleet1->isEmpty($x, $y)) {
                    $missCell = [$x, $y];
                }
            }
        }

        $this->game->applyMove($state, 'p0', ['action' => 'fire', 'x' => $hitCell[0], 'y' => $hitCell[1]]);
        self::assertSame('p0', $state->currentPlayer()->id, 'a hit should keep the turn');

        $this->game->applyMove($state, 'p0', ['action' => 'fire', 'x' => $missCell[0], 'y' => $missCell[1]]);
        self::assertSame('p1', $state->currentPlayer()->id, 'a miss should still pass the turn');
    }

    public function testCannotFireTheSameCellTwice(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'randomize']);
        $this->game->applyMove($state, 'p1', ['action' => 'randomize']);

        $this->game->applyMove($state, 'p0', ['action' => 'fire', 'x' => 0, 'y' => 0]);

        try {
            $this->game->applyMove($state, 'p1', ['action' => 'fire', 'x' => 5, 'y' => 5]);
            $this->game->applyMove($state, 'p0', ['action' => 'fire', 'x' => 0, 'y' => 0]);
            self::fail('expected refiring the same cell to be rejected');
        } catch (InvalidMoveException) {
            self::assertNotNull($state->data['shots']['p0']->get(0, 0));
        }
    }

    public function testSinkingEveryShipWins(): void
    {
        $state = $this->state(['shipsLine5' => 0, 'shipsLine4' => 0, 'shipsLine3' => 0, 'shipsLine2' => 1]);
        // single 2-cell ship for p1, placed manually for a deterministic finish
        $this->game->applyMove($state, 'p0', ['action' => 'randomize']);
        $this->game->applyMove($state, 'p1', ['action' => 'place', 'index' => 0, 'x' => 0, 'y' => 0, 'orientation' => 0]);

        $this->game->applyMove($state, 'p0', ['action' => 'fire', 'x' => 0, 'y' => 0]);
        self::assertSame(GameStatus::Running, $state->status);

        $this->game->applyMove($state, 'p1', ['action' => 'fire', 'x' => 9, 'y' => 9]);
        $this->game->applyMove($state, 'p0', ['action' => 'fire', 'x' => 1, 'y' => 0]);

        self::assertSame(GameStatus::Finished, $state->status);
        self::assertSame('p0', $state->winnerId);
    }
}
