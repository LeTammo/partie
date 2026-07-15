<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Games\Codewords\GameDefinition;
use App\Game\Games\Codewords\GameRenderer;
use App\Game\Games\Codewords\GameRules;
use App\Game\Games\Codewords\WordList;

final class CodewordsTest extends GameTestCase
{
    private GameDefinition $game;
    private GameRenderer $renderer;

    protected function setUp(): void
    {
        $wordList = new WordList(\dirname(__DIR__, 2).'/assets/lib/words');
        $rules = new GameRules($wordList);
        $this->renderer = new GameRenderer($rules);
        $this->game = new GameDefinition($rules, $this->renderer);
    }

    private function state(int $playerCount = 4): GameState
    {
        return $this->game->createInitialState(self::players($playerCount));
    }

    /**
     * p0/p1 -> red informant/detective, p2/p3 -> blue informant/detective.
     */
    private function assignStandardTeams(GameState $state): void
    {
        $this->game->applyMove($state, 'p0', ['action' => 'assign', 'team' => 'red', 'role' => 'informant']);
        $this->game->applyMove($state, 'p1', ['action' => 'assign', 'team' => 'red', 'role' => 'detective']);
        $this->game->applyMove($state, 'p2', ['action' => 'assign', 'team' => 'blue', 'role' => 'informant']);
        $this->game->applyMove($state, 'p3', ['action' => 'assign', 'team' => 'blue', 'role' => 'detective']);
    }

    public function testInitialStateStartsInSetupPhaseWithABoard(): void
    {
        $state = $this->state();

        self::assertSame('setup', $state->data['phase']);
        self::assertCount(GameRules::WORD_COUNT, $state->data['words']);
        self::assertCount(GameRules::WORD_COUNT, $state->data['colors']);
        self::assertSame(array_fill(0, GameRules::WORD_COUNT, false), $state->data['revealed']);
        self::assertContains($state->data['startingTeam'], GameRules::TEAMS);
    }

    public function testCannotStartUntilBothTeamsHaveAnInformantAndADetective(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'assign', 'team' => 'red', 'role' => 'informant']);

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'begin']);
            self::fail('cannot begin before every player is assigned and both teams are complete');
        } catch (InvalidMoveException) {
            self::assertSame('setup', $state->data['phase']);
        }
    }

    public function testSecondPlayerCannotStealTheInformantSlot(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'assign', 'team' => 'red', 'role' => 'informant']);

        try {
            $this->game->applyMove($state, 'p1', ['action' => 'assign', 'team' => 'red', 'role' => 'informant']);
            self::fail('a team can only have one informant');
        } catch (InvalidMoveException) {
            self::assertSame('red', $state->data['assignments']['p0']['team']);
        }
    }

    public function testBeginningTheGameMovesToCluePhaseForTheStartingTeam(): void
    {
        $state = $this->state();
        $this->assignStandardTeams($state);
        $this->game->applyMove($state, 'p0', ['action' => 'begin']);

        self::assertSame('clue', $state->data['phase']);
        self::assertSame($state->data['startingTeam'], $state->data['activeTeam']);
    }

    public function testOnlyTheActiveInformantMayGiveAClue(): void
    {
        $state = $this->state();
        $this->assignStandardTeams($state);
        $this->game->applyMove($state, 'p0', ['action' => 'begin']);
        $otherTeamInformant = 'red' === $state->data['activeTeam'] ? 'p2' : 'p0';

        try {
            $this->game->applyMove($state, $otherTeamInformant, ['action' => 'clue', 'word' => 'Baum', 'count' => 2]);
            self::fail('only the active informant may give a clue');
        } catch (InvalidMoveException) {
            self::assertNull($state->data['clue']);
        }
    }

    public function testClueMovesToGuessPhaseAndSetsGuessesRemaining(): void
    {
        $state = $this->state();
        $this->assignStandardTeams($state);
        $this->game->applyMove($state, 'p0', ['action' => 'begin']);
        $activeInformant = 'red' === $state->data['activeTeam'] ? 'p0' : 'p2';

        $this->game->applyMove($state, $activeInformant, ['action' => 'clue', 'word' => 'Baum', 'count' => 2]);

        self::assertSame('guess', $state->data['phase']);
        self::assertSame(['word' => 'Baum', 'count' => 2], $state->data['clue']);
        self::assertSame(3, $state->data['guessesRemaining']);
    }

    public function testGuessingAWordOfTheActiveTeamKeepsTheTurnAndDecrementsGuesses(): void
    {
        $state = $this->state();
        $this->assignStandardTeams($state);
        $this->game->applyMove($state, 'p0', ['action' => 'begin']);
        $activeTeam = $state->data['activeTeam'];
        $informant = 'red' === $activeTeam ? 'p0' : 'p2';
        $detective = 'red' === $activeTeam ? 'p1' : 'p3';

        $this->game->applyMove($state, $informant, ['action' => 'clue', 'word' => 'Baum', 'count' => 2]);
        $ownIndex = array_search($activeTeam, $state->data['colors'], true);

        $this->game->applyMove($state, $detective, ['action' => 'guess', 'index' => $ownIndex]);

        self::assertTrue($state->data['revealed'][$ownIndex]);
        self::assertSame('guess', $state->data['phase']);
        self::assertSame($activeTeam, $state->data['activeTeam']);
        self::assertSame(2, $state->data['guessesRemaining']);
    }

    public function testGuessingACivilianEndsTheTurnImmediately(): void
    {
        $state = $this->state();
        $this->assignStandardTeams($state);
        $this->game->applyMove($state, 'p0', ['action' => 'begin']);
        $activeTeam = $state->data['activeTeam'];
        $informant = 'red' === $activeTeam ? 'p0' : 'p2';
        $detective = 'red' === $activeTeam ? 'p1' : 'p3';

        $this->game->applyMove($state, $informant, ['action' => 'clue', 'word' => 'Baum', 'count' => 2]);
        $civilianIndex = array_search('civilian', $state->data['colors'], true);

        $this->game->applyMove($state, $detective, ['action' => 'guess', 'index' => $civilianIndex]);

        self::assertSame('clue', $state->data['phase']);
        self::assertSame($this->otherTeam($activeTeam), $state->data['activeTeam']);
        self::assertNull($state->data['clue']);
    }

    public function testRevealingTheSuspectEndsTheGameForTheOtherTeam(): void
    {
        $state = $this->state();
        $this->assignStandardTeams($state);
        $this->game->applyMove($state, 'p0', ['action' => 'begin']);
        $activeTeam = $state->data['activeTeam'];
        $informant = 'red' === $activeTeam ? 'p0' : 'p2';
        $detective = 'red' === $activeTeam ? 'p1' : 'p3';

        $this->game->applyMove($state, $informant, ['action' => 'clue', 'word' => 'Baum', 'count' => 2]);
        $suspectIndex = array_search('suspect', $state->data['colors'], true);

        $this->game->applyMove($state, $detective, ['action' => 'guess', 'index' => $suspectIndex]);

        self::assertSame(GameStatus::Finished, $state->status);
        $winningTeam = $this->otherTeam($activeTeam);
        $winningInformant = 'red' === $winningTeam ? 'p0' : 'p2';
        self::assertSame($winningInformant, $state->winnerId);
        self::assertSame($winningTeam, $state->data['winningTeam']);
    }

    public function testWholeWinningTeamIsMarkedAsWonNotJustTheInformant(): void
    {
        $state = $this->state();
        $this->assignStandardTeams($state);
        $this->game->applyMove($state, 'p0', ['action' => 'begin']);
        $activeTeam = $state->data['activeTeam'];
        $informant = 'red' === $activeTeam ? 'p0' : 'p2';
        $detective = 'red' === $activeTeam ? 'p1' : 'p3';

        $this->game->applyMove($state, $informant, ['action' => 'clue', 'word' => 'Baum', 'count' => 2]);
        $suspectIndex = array_search('suspect', $state->data['colors'], true);
        $this->game->applyMove($state, $detective, ['action' => 'guess', 'index' => $suspectIndex]);

        $winningTeam = $this->otherTeam($activeTeam);
        $view = $this->renderer->buildView($state, $detective);

        foreach ($view['players'] as $player) {
            self::assertSame($winningTeam === $player['team'], $player['won']);
        }
    }

    public function testNoMoreMovesAreAcceptedOnceTheGameIsFinished(): void
    {
        $state = $this->state();
        $this->assignStandardTeams($state);
        $this->game->applyMove($state, 'p0', ['action' => 'begin']);
        $activeTeam = $state->data['activeTeam'];
        $informant = 'red' === $activeTeam ? 'p0' : 'p2';
        $detective = 'red' === $activeTeam ? 'p1' : 'p3';

        $this->game->applyMove($state, $informant, ['action' => 'clue', 'word' => 'Baum', 'count' => 2]);
        $suspectIndex = array_search('suspect', $state->data['colors'], true);
        $this->game->applyMove($state, $detective, ['action' => 'guess', 'index' => $suspectIndex]);

        $anotherIndex = array_search(false, $state->data['revealed'], true);
        try {
            $this->game->applyMove($state, $detective, ['action' => 'guess', 'index' => $anotherIndex]);
            self::fail('no further moves should be accepted after the game finished');
        } catch (InvalidMoveException) {
            self::assertFalse($state->data['revealed'][$anotherIndex]);
        }
    }

    public function testRevealingAllOwnWordsWinsImmediatelyEvenMidGuess(): void
    {
        $state = $this->state();
        $this->assignStandardTeams($state);
        $this->game->applyMove($state, 'p0', ['action' => 'begin']);
        $activeTeam = $state->data['activeTeam'];
        $informant = 'red' === $activeTeam ? 'p0' : 'p2';
        $detective = 'red' === $activeTeam ? 'p1' : 'p3';

        $this->game->applyMove($state, $informant, ['action' => 'clue', 'word' => 'Baum', 'count' => 9]);

        $ownIndexes = array_keys($state->data['colors'], $activeTeam, true);
        foreach ($ownIndexes as $index) {
            if (GameStatus::Finished === $state->status) {
                break;
            }
            $this->game->applyMove($state, $detective, ['action' => 'guess', 'index' => $index]);
        }

        self::assertSame(GameStatus::Finished, $state->status);
        $winningInformant = 'red' === $activeTeam ? 'p0' : 'p2';
        self::assertSame($winningInformant, $state->winnerId);
    }

    public function testPassEndsTheTurnWithoutRevealingAnything(): void
    {
        $state = $this->state();
        $this->assignStandardTeams($state);
        $this->game->applyMove($state, 'p0', ['action' => 'begin']);
        $activeTeam = $state->data['activeTeam'];
        $informant = 'red' === $activeTeam ? 'p0' : 'p2';
        $detective = 'red' === $activeTeam ? 'p1' : 'p3';

        $this->game->applyMove($state, $informant, ['action' => 'clue', 'word' => 'Baum', 'count' => 2]);
        $this->game->applyMove($state, $detective, ['action' => 'pass']);

        self::assertSame('clue', $state->data['phase']);
        self::assertSame($this->otherTeam($activeTeam), $state->data['activeTeam']);
        self::assertSame(array_fill(0, GameRules::WORD_COUNT, false), $state->data['revealed']);
    }

    public function testClueCannotBeAWordOnTheBoard(): void
    {
        $state = $this->state();
        $this->assignStandardTeams($state);
        $this->game->applyMove($state, 'p0', ['action' => 'begin']);
        $activeTeam = $state->data['activeTeam'];
        $informant = 'red' === $activeTeam ? 'p0' : 'p2';
        $boardWord = $state->data['words'][0];

        try {
            $this->game->applyMove($state, $informant, ['action' => 'clue', 'word' => $boardWord, 'count' => 1]);
            self::fail('the clue cannot be a word already on the board');
        } catch (InvalidMoveException) {
            self::assertSame('clue', $state->data['phase']);
        }
    }

    private function otherTeam(string $team): string
    {
        return 'red' === $team ? 'blue' : 'red';
    }
}
