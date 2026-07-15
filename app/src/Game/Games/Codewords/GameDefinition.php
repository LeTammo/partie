<?php

declare(strict_types=1);

namespace App\Game\Games\Codewords;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameSetting;
use App\Game\Core\Model\GameSettingType;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Player;
use App\Game\Core\Service\AbstractGameDefinition;

final readonly class GameDefinition extends AbstractGameDefinition
{
    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'codewords';
    }

    public function getName(): string
    {
        return 'game.codewords.name';
    }

    public function getDescription(): string
    {
        return 'game.codewords.description';
    }

    public function getIcon(): string
    {
        return 'codewords';
    }

    public function getMinPlayers(): int
    {
        return 4;
    }

    public function getMaxPlayers(): int
    {
        return 8;
    }

    public function settings(): array
    {
        return [
            new GameSetting(
                key: 'wordList',
                labelKey: 'setting.codewords.word_list',
                type: GameSettingType::Enum,
                default: 'deutsch',
                options: [
                    'deutsch' => 'setting.codewords.word_list.deutsch',
                    'deutsch_simpel' => 'setting.codewords.word_list.deutsch_simpel',
                    'english' => 'setting.codewords.word_list.english',
                ],
            ),
        ];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;

        $board = $this->rules->buildBoard((string) ($settings['wordList'] ?? 'deutsch'));

        $state->data['phase'] = 'setup';
        $state->data['assignments'] = array_fill_keys(
            array_map(static fn (Player $player): string => $player->id, $players),
            ['team' => null, 'role' => null],
        );
        $state->data['words'] = $board['words'];
        $state->data['colors'] = $board['colors'];
        $state->data['revealed'] = array_fill(0, GameRules::WORD_COUNT, false);
        $state->data['startingTeam'] = $board['startingTeam'];
        $state->data['activeTeam'] = null;
        $state->data['clue'] = null;
        $state->data['guessesRemaining'] = 0;
        $state->data['winningTeam'] = null;

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (GameStatus::Running !== $state->status) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'assign' => $this->assign($state, $playerId, $this->stringParam($payload, 'team'), $this->stringParam($payload, 'role')),
            'begin' => $this->begin($state, $playerId),
            'clue' => $this->clue($state, $playerId, $this->stringParam($payload, 'word'), $this->intParam($payload, 'count')),
            'guess' => $this->guess($state, $playerId, $this->intParam($payload, 'index')),
            'pass' => $this->pass($state, $playerId),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/codewords/board.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function assign(GameState $state, string $playerId, string $team, string $role): void
    {
        if ('setup' !== $state->data['phase']) {
            $this->invalidMove('error.codewords.not_setup');
        }
        if (!\in_array($team, GameRules::TEAMS, true) || !\in_array($role, ['informant', 'detective'], true)) {
            throw new InvalidMoveException('error.unknown_action');
        }

        $currentInformant = $this->rules->informantIdOf($state->data['assignments'], $team);
        if ('informant' === $role && null !== $currentInformant && $currentInformant !== $playerId) {
            $this->invalidMove('error.codewords.informant_taken');
        }

        $state->data['assignments'][$playerId] = ['team' => $team, 'role' => $role];

        $state->logGameEvent('log.codewords.joined_team', [
            '%player%' => $state->playerById($playerId)->nickname,
            '%role%' => 't:codewords:codewords.role.'.$role,
            '%team%' => 't:codewords:codewords.team.'.$team,
        ]);
    }

    private function begin(GameState $state, string $playerId): void
    {
        if ('setup' !== $state->data['phase']) {
            $this->invalidMove('error.codewords.not_setup');
        }
        if (null === ($state->data['assignments'][$playerId]['team'] ?? null)) {
            $this->invalidMove('error.codewords.not_assigned');
        }
        foreach ($state->players as $player) {
            if (null === $state->data['assignments'][$player->id]['team']) {
                $this->invalidMove('error.codewords.players_not_assigned');
            }
        }
        if (!$this->rules->teamsReady($state->data['assignments'])) {
            $this->invalidMove('error.codewords.teams_not_ready');
        }

        $state->data['phase'] = 'clue';
        $state->data['activeTeam'] = $state->data['startingTeam'];
        $this->setTurnToInformant($state);

        $state->logGameEvent('log.codewords.started', ['%team%' => 't:codewords:codewords.team.'.$state->data['activeTeam']]);
    }

    private function clue(GameState $state, string $playerId, string $word, int $count): void
    {
        if ('clue' !== $state->data['phase']) {
            $this->invalidMove('error.codewords.not_clue_phase');
        }

        $activeTeam = $state->data['activeTeam'];
        if ($playerId !== $this->rules->informantIdOf($state->data['assignments'], $activeTeam)) {
            $this->invalidMove('error.codewords.not_your_clue');
        }

        $word = trim($word);
        if ('' === $word) {
            $this->invalidMove('error.codewords.empty_clue');
        }
        if ($count < 0 || $count > 9) {
            $this->invalidMove('error.codewords.invalid_count');
        }
        if ($this->rules->wordOnBoard($state->data['words'], $word)) {
            $this->invalidMove('error.codewords.clue_on_board');
        }

        $state->data['clue'] = ['word' => $word, 'count' => $count];
        $state->data['guessesRemaining'] = $count + 1;
        $state->data['phase'] = 'guess';

        $state->logGameEvent('log.codewords.clue_given', [
            '%player%' => $state->playerById($playerId)->nickname,
            '%word%' => $word,
            '%count%' => $count,
        ]);
    }

    private function guess(GameState $state, string $playerId, int $index): void
    {
        if ('guess' !== $state->data['phase']) {
            $this->invalidMove('error.codewords.not_guess_phase');
        }
        if (!$this->isActiveDetective($state, $playerId)) {
            $this->invalidMove('error.codewords.not_your_guess');
        }
        if (!isset($state->data['colors'][$index]) || $state->data['revealed'][$index]) {
            $this->invalidMove('error.codewords.already_revealed');
        }

        $state->data['revealed'][$index] = true;
        $color = $state->data['colors'][$index];
        $word = $state->data['words'][$index];
        $activeTeam = $state->data['activeTeam'];
        $player = $state->playerById($playerId);

        if ('suspect' === $color) {
            $state->logGameEvent('log.codewords.suspect_revealed', ['%player%' => $player->nickname, '%word%' => $word]);
            $this->finishForTeam($state, $this->rules->otherTeam($activeTeam));

            return;
        }

        $state->logGameEvent('log.codewords.guessed', ['%player%' => $player->nickname, '%word%' => $word]);

        foreach (GameRules::TEAMS as $team) {
            if ($this->rules->teamFullyRevealed($state->data['colors'], $state->data['revealed'], $team)) {
                $this->finishForTeam($state, $team);

                return;
            }
        }

        if ($color === $activeTeam) {
            --$state->data['guessesRemaining'];
            if ($state->data['guessesRemaining'] <= 0) {
                $this->endTurn($state);
            }

            return;
        }

        $this->endTurn($state);
    }

    private function pass(GameState $state, string $playerId): void
    {
        if ('guess' !== $state->data['phase']) {
            $this->invalidMove('error.codewords.not_guess_phase');
        }
        if (!$this->isActiveDetective($state, $playerId)) {
            $this->invalidMove('error.codewords.not_your_guess');
        }

        $state->logGameEvent('log.codewords.passed', ['%player%' => $state->playerById($playerId)->nickname]);
        $this->endTurn($state);
    }

    private function isActiveDetective(GameState $state, string $playerId): bool
    {
        $assignment = $state->data['assignments'][$playerId] ?? null;

        return null !== $assignment
            && $assignment['team'] === $state->data['activeTeam']
            && 'detective' === $assignment['role'];
    }

    private function endTurn(GameState $state): void
    {
        $state->data['activeTeam'] = $this->rules->otherTeam($state->data['activeTeam']);
        $state->data['clue'] = null;
        $state->data['guessesRemaining'] = 0;
        $state->data['phase'] = 'clue';
        $this->setTurnToInformant($state);
    }

    private function setTurnToInformant(GameState $state): void
    {
        $informantId = $this->rules->informantIdOf($state->data['assignments'], $state->data['activeTeam']);
        $state->currentTurnIndex = $state->playerById($informantId)->seat;
    }

    private function finishForTeam(GameState $state, string $team): void
    {
        $informantId = $this->rules->informantIdOf($state->data['assignments'], $team);
        $state->data['winningTeam'] = $team;
        $state->finish($informantId);
        $state->logGameEvent('log.codewords.team_won', ['%team%' => 't:codewords:codewords.team.'.$team]);
    }
}
