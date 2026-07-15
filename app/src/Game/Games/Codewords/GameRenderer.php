<?php

declare(strict_types=1);

namespace App\Game\Games\Codewords;

use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Player;
use App\Game\Core\View\PlayerViews;

final readonly class GameRenderer
{
    public function __construct(private GameRules $rules)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $data = $state->data;
        $assignments = $data['assignments'];
        $myAssignment = null !== $viewerId ? ($assignments[$viewerId] ?? null) : null;
        $myTeam = $myAssignment['team'] ?? null;
        $myRole = $myAssignment['role'] ?? null;
        $winningTeam = $data['winningTeam'] ?? null;

        $players = PlayerViews::build($state, static fn (Player $player): array => [
            'id' => $player->id,
            'team' => $assignments[$player->id]['team'] ?? null,
            'role' => $assignments[$player->id]['role'] ?? null,
            'won' => null !== $winningTeam && $winningTeam === ($assignments[$player->id]['team'] ?? null),
        ]);

        if ('setup' === $data['phase']) {
            return [
                'phase' => 'setup',
                'players' => $players,
                'myTeam' => $myTeam,
                'myRole' => $myRole,
                'redInformantAvailable' => $this->informantSlotAvailable($assignments, 'red', $viewerId),
                'blueInformantAvailable' => $this->informantSlotAvailable($assignments, 'blue', $viewerId),
                'canBegin' => null !== $myTeam && $this->rules->teamsReady($assignments),
            ];
        }

        $activeTeam = $data['activeTeam'];
        $gameOver = GameStatus::Finished === $state->status;
        $isInformant = 'informant' === $myRole;
        $canGiveClue = !$gameOver && 'clue' === $data['phase'] && $myTeam === $activeTeam && $isInformant;
        $canGuess = !$gameOver && 'guess' === $data['phase'] && $myTeam === $activeTeam && 'detective' === $myRole;

        $cells = [];
        foreach ($data['words'] as $i => $word) {
            $revealed = $data['revealed'][$i];
            $cells[] = [
                'index' => $i,
                'word' => $word,
                'revealed' => $revealed,
                'color' => $revealed || $isInformant || $gameOver ? $data['colors'][$i] : null,
                'guessable' => $canGuess && !$revealed,
            ];
        }

        return [
            'phase' => $data['phase'],
            'players' => $players,
            'myTeam' => $myTeam,
            'myRole' => $myRole,
            'activeTeam' => $activeTeam,
            'clue' => $data['clue'],
            'guessesRemaining' => $data['guessesRemaining'],
            'cells' => $cells,
            'canGiveClue' => $canGiveClue,
            'canGuess' => $canGuess,
            'canPass' => $canGuess,
            'gameOver' => $gameOver,
            'winningTeam' => $winningTeam,
            'redRemaining' => $this->rules->remainingCount($data['colors'], $data['revealed'], 'red'),
            'blueRemaining' => $this->rules->remainingCount($data['colors'], $data['revealed'], 'blue'),
        ];
    }

    /**
     * @param array<string, array{team: ?string, role: ?string}> $assignments
     */
    private function informantSlotAvailable(array $assignments, string $team, ?string $viewerId): bool
    {
        $current = $this->rules->informantIdOf($assignments, $team);

        return null === $current || $current === $viewerId;
    }
}
