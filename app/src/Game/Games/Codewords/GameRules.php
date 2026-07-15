<?php

declare(strict_types=1);

namespace App\Game\Games\Codewords;

final readonly class GameRules
{
    public const int WORD_COUNT = 25;
    public const int STARTING_TEAM_COUNT = 9;
    public const int OTHER_TEAM_COUNT = 8;
    public const int CIVILIAN_COUNT = 7;
    public const int SUSPECT_COUNT = 1;

    /** @var list<string> */
    public const array TEAMS = ['red', 'blue'];

    public function __construct(private WordList $wordList)
    {
    }

    /**
     * @return array{words: list<string>, colors: list<string>, startingTeam: string}
     */
    public function buildBoard(string $listKey): array
    {
        $startingTeam = self::TEAMS[random_int(0, 1)];
        $otherTeam = $this->otherTeam($startingTeam);

        $colors = [
            ...array_fill(0, self::STARTING_TEAM_COUNT, $startingTeam),
            ...array_fill(0, self::OTHER_TEAM_COUNT, $otherTeam),
            ...array_fill(0, self::CIVILIAN_COUNT, 'civilian'),
            ...array_fill(0, self::SUSPECT_COUNT, 'suspect'),
        ];
        shuffle($colors);

        return [
            'words' => $this->wordList->pick($listKey, self::WORD_COUNT),
            'colors' => $colors,
            'startingTeam' => $startingTeam,
        ];
    }

    public function otherTeam(string $team): string
    {
        return 'red' === $team ? 'blue' : 'red';
    }

    /**
     * @param array<string, array{team: ?string, role: ?string}> $assignments
     */
    public function informantIdOf(array $assignments, string $team): ?string
    {
        foreach ($assignments as $playerId => $assignment) {
            if ($team === $assignment['team'] && 'informant' === $assignment['role']) {
                return $playerId;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{team: ?string, role: ?string}> $assignments
     */
    public function teamsReady(array $assignments): bool
    {
        foreach (self::TEAMS as $team) {
            $roles = array_column(
                array_filter($assignments, static fn (array $a): bool => $team === $a['team']),
                'role',
            );

            if (!\in_array('informant', $roles, true) || !\in_array('detective', $roles, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $words
     */
    public function wordOnBoard(array $words, string $clue): bool
    {
        $needle = mb_strtolower($clue);

        foreach ($words as $word) {
            if (mb_strtolower($word) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $colors
     * @param list<bool>   $revealed
     */
    public function teamFullyRevealed(array $colors, array $revealed, string $team): bool
    {
        return 0 === $this->remainingCount($colors, $revealed, $team);
    }

    /**
     * @param list<string> $colors
     * @param list<bool>   $revealed
     */
    public function remainingCount(array $colors, array $revealed, string $team): int
    {
        $count = 0;
        foreach ($colors as $i => $color) {
            if ($team === $color && !$revealed[$i]) {
                ++$count;
            }
        }

        return $count;
    }
}
