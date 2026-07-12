<?php

declare(strict_types=1);

namespace App\Game\Games\DicePoker;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\Dice;
use App\Game\Core\Model\GameSetting;
use App\Game\Core\Model\GameSettingType;
use App\Game\Core\Model\GameState;
use App\Game\Core\Service\AbstractGameDefinition;

final readonly class GameDefinition extends AbstractGameDefinition
{
    private const int DICE_COUNT = 5;
    private const int ROLLS_PER_TURN = 3;

    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'dicepoker';
    }

    public function getName(): string
    {
        return 'game.dicepoker.name';
    }

    public function getDescription(): string
    {
        return 'game.dicepoker.description';
    }

    public function getIcon(): string
    {
        return 'die';
    }

    public function getMinPlayers(): int
    {
        return 1;
    }

    public function getMaxPlayers(): int
    {
        return 6;
    }

    public function settings(): array
    {
        return [
            new GameSetting(
                key: 'rollsPerTurn',
                labelKey: 'setting.dicepoker.rolls_per_turn',
                type: GameSettingType::Int,
                default: self::ROLLS_PER_TURN,
                min: 1,
                max: 5,
            ),
        ];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;

        for ($i = 0; $i < self::DICE_COUNT; ++$i) {
            $state->dice[] = new Dice(maxFaces: 6);
        }

        $emptyCard = array_fill_keys(GameRules::allCategories(), null);
        foreach ($players as $player) {
            $state->data['scorecards'][$player->id] = $emptyCard;
        }
        $state->data['rollsPerTurn'] = (int) ($settings['rollsPerTurn'] ?? self::ROLLS_PER_TURN);
        $state->data['rollsLeft'] = $state->data['rollsPerTurn'];
        $state->data['hasRolled'] = false;

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'roll' => $this->roll($state),
            'toggle' => $this->toggleLock($state, $this->intParam($payload, 'die')),
            'score' => $this->scoreCategory($state, $playerId, $this->stringParam($payload, 'category')),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/dicepoker/table.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function roll(GameState $state): void
    {
        if ($state->data['rollsLeft'] <= 0) {
            $this->invalidMove('error.dicepoker.no_rolls_left');
        }

        foreach ($state->dice as $die) {
            $die->roll();
        }
        --$state->data['rollsLeft'];
        $state->data['hasRolled'] = true;

        $values = implode(' ', array_map(static fn (Dice $d): int => $d->value, $state->dice));
        $state->logGameEvent('log.dicepoker.rolled', [
            '%player%' => $state->currentPlayer()->nickname,
            '%values%' => $values,
            '%left%' => $state->data['rollsLeft'],
        ]);
    }

    private function toggleLock(GameState $state, int $index): void
    {
        if (!$state->data['hasRolled']) {
            $this->invalidMove('error.dicepoker.roll_first');
        }
        if (!isset($state->dice[$index])) {
            $this->invalidMove('error.dicepoker.unknown_die');
        }
        if ($state->data['rollsLeft'] <= 0) {
            $this->invalidMove('error.dicepoker.no_rolls_hold');
        }

        $state->dice[$index]->toggleLock();
    }

    private function scoreCategory(GameState $state, string $playerId, string $category): void
    {
        if (!$state->data['hasRolled']) {
            $this->invalidMove('error.dicepoker.roll_first');
        }

        $scorecard = &$state->data['scorecards'][$playerId];
        if (!\array_key_exists($category, $scorecard)) {
            $this->invalidMove('error.dicepoker.unknown_category');
        }
        if (null !== $scorecard[$category]) {
            $this->invalidMove('error.dicepoker.category_filled');
        }

        $values = array_map(static fn (Dice $d): int => $d->value, $state->dice);
        $points = $this->rules->score($category, $values);
        $scorecard[$category] = $points;

        $player = $state->currentPlayer();
        $state->logGameEvent('log.dicepoker.scored', [
            '%player%' => $player->nickname,
            '%points%' => $points,
            '%category%' => 't:dicepoker:dicepoker.category.'.$category,
        ]);

        foreach ($state->dice as $die) {
            $die->locked = false;
            $die->value = 1;
        }
        $state->data['rollsLeft'] = $state->data['rollsPerTurn'];
        $state->data['hasRolled'] = false;

        if ($this->isGameComplete($state)) {
            $this->finishGame($state);

            return;
        }

        $state->advanceTurn();
    }

    private function isGameComplete(GameState $state): bool
    {
        foreach ($state->data['scorecards'] as $scorecard) {
            if (!$this->rules->isComplete($scorecard)) {
                return false;
            }
        }

        return true;
    }

    private function finishGame(GameState $state): void
    {
        $best = null;
        $bestScore = -1;
        $totals = [];

        foreach ($state->players as $player) {
            $total = $this->rules->total($state->data['scorecards'][$player->id]);
            $totals[] = sprintf('%s: %d', $player->nickname, $total);
            if ($total > $bestScore) {
                $bestScore = $total;
                $best = $player;
            }
        }

        $state->finish($best?->id);
        $state->logGameEvent('log.dicepoker.final', ['%scores%' => implode(', ', $totals)]);
        if (null !== $best) {
            $state->logGameEvent('log.dicepoker.won', ['%player%' => $best->nickname, '%points%' => $bestScore]);
        }
    }
}
