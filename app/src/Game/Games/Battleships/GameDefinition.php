<?php

declare(strict_types=1);

namespace App\Game\Games\Battleships;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\Board;
use App\Game\Core\Model\GameSetting;
use App\Game\Core\Model\GameSettingType;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\Player;
use App\Game\Core\Model\Token;
use App\Game\Core\Service\AbstractGameDefinition;

final readonly class GameDefinition extends AbstractGameDefinition
{
    /** @var float ships may fill at most this fraction of the board, leaving room for random placement to succeed */
    private const float MAX_FILL_RATIO = 0.7;

    private const int RANDOMIZE_RESTARTS = 20;
    private const int RANDOMIZE_ATTEMPTS_PER_SHIP = 500;

    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'battleships';
    }

    public function getName(): string
    {
        return 'game.battleships.name';
    }

    public function getDescription(): string
    {
        return 'game.battleships.description';
    }

    public function getIcon(): string
    {
        return 'games/battleships';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 2;
    }

    public function settings(): array
    {
        return [
            new GameSetting(key: 'gridWidth', labelKey: 'setting.battleships.grid_width', type: GameSettingType::Int, default: 10, min: 6, max: 16),
            new GameSetting(key: 'gridHeight', labelKey: 'setting.battleships.grid_height', type: GameSettingType::Int, default: 10, min: 6, max: 16),
            new GameSetting(key: 'shipsLine2', labelKey: 'setting.battleships.ships_line2', type: GameSettingType::Int, default: 1, min: 0, max: 4, previewCells: GameRules::SHAPES['line2']),
            new GameSetting(key: 'shipsLine3', labelKey: 'setting.battleships.ships_line3', type: GameSettingType::Int, default: 2, min: 0, max: 4, previewCells: GameRules::SHAPES['line3']),
            new GameSetting(key: 'shipsLine4', labelKey: 'setting.battleships.ships_line4', type: GameSettingType::Int, default: 1, min: 0, max: 4, previewCells: GameRules::SHAPES['line4']),
            new GameSetting(key: 'shipsLine5', labelKey: 'setting.battleships.ships_line5', type: GameSettingType::Int, default: 1, min: 0, max: 4, previewCells: GameRules::SHAPES['line5']),
            new GameSetting(key: 'shipsSquare4', labelKey: 'setting.battleships.ships_square4', type: GameSettingType::Int, default: 0, min: 0, max: 4, previewCells: GameRules::SHAPES['square4']),
            new GameSetting(key: 'shipsSquare6', labelKey: 'setting.battleships.ships_square6', type: GameSettingType::Int, default: 0, min: 0, max: 4, previewCells: GameRules::SHAPES['square6']),
            new GameSetting(key: 'shipsL', labelKey: 'setting.battleships.ships_l', type: GameSettingType::Int, default: 0, min: 0, max: 4, previewCells: GameRules::SHAPES['l']),
            new GameSetting(key: 'shipsS4', labelKey: 'setting.battleships.ships_s4', type: GameSettingType::Int, default: 0, min: 0, max: 4, previewCells: GameRules::SHAPES['s4']),
            new GameSetting(key: 'shipsV', labelKey: 'setting.battleships.ships_v', type: GameSettingType::Int, default: 0, min: 0, max: 4, previewCells: GameRules::SHAPES['v']),
            new GameSetting(key: 'shipsS5', labelKey: 'setting.battleships.ships_s5', type: GameSettingType::Int, default: 0, min: 0, max: 4, previewCells: GameRules::SHAPES['s5']),
            new GameSetting(key: 'extraTurnOnHit', labelKey: 'setting.battleships.extra_turn_on_hit', type: GameSettingType::Bool, default: true),
        ];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;

        $options = Options::fromSettings($settings);
        $pool = $this->fitPool($this->rules->shapePool($options->shapeCounts), $options->gridWidth, $options->gridHeight);

        $state->data['shapePool'] = $pool;
        $state->data['totalCells'] = array_sum(array_map(
            static fn (string $shape): int => \count(GameRules::SHAPES[$shape]),
            $pool,
        ));
        $state->data['phase'] = 'placing';

        foreach ($players as $player) {
            $state->data['fleets'][$player->id] = new Board($options->gridWidth, $options->gridHeight);
            $state->data['shots'][$player->id] = new Board($options->gridWidth, $options->gridHeight);
            $state->data['hits'][$player->id] = [];
            $state->data['placed'][$player->id] = [];
            $state->data['placedCount'][$player->id] = 0;
            $state->data['ready'][$player->id] = false;
        }

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        $action = $payload['action'] ?? '';

        if ('placing' === $state->data['phase']) {
            match ($action) {
                'place' => $this->place(
                    $state,
                    $playerId,
                    $this->intParam($payload, 'index'),
                    $this->intParam($payload, 'x'),
                    $this->intParam($payload, 'y'),
                    $this->intParam($payload, 'orientation'),
                ),
                'randomize' => $this->randomize($state, $playerId),
                'reset' => $this->reset($state, $playerId),
                default => throw new InvalidMoveException('error.unknown_action'),
            };

            return;
        }

        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($action) {
            'fire' => $this->fire($state, $playerId, $this->intParam($payload, 'x'), $this->intParam($payload, 'y')),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/battleships/table.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    /**
     * @param list<string> $pool
     *
     * @return list<string> a prefix of $pool whose combined cell count fits the board
     */
    private function fitPool(array $pool, int $width, int $height): array
    {
        $capacity = (int) floor($width * $height * self::MAX_FILL_RATIO);
        $total = 0;
        $fitted = [];
        foreach ($pool as $shape) {
            $cells = \count(GameRules::SHAPES[$shape]);
            if ($total + $cells > $capacity) {
                break;
            }
            $fitted[] = $shape;
            $total += $cells;
        }

        return $fitted;
    }

    private function assertPlacing(GameState $state, string $playerId): void
    {
        if (null === $state->playerById($playerId)) {
            throw new InvalidMoveException('error.unknown_action');
        }
        if ($state->data['ready'][$playerId]) {
            $this->invalidMove('error.battleships.already_ready');
        }
    }

    private function place(GameState $state, string $playerId, int $poolIndex, int $x, int $y, int $orientation): void
    {
        $this->assertPlacing($state, $playerId);

        $pool = $state->data['shapePool'];
        if (!isset($pool[$poolIndex]) || isset($state->data['placed'][$playerId][$poolIndex])) {
            $this->invalidMove('error.battleships.unknown_ship');
        }

        $fleet = $state->data['fleets'][$playerId];
        $cells = $this->rules->shapeCells($pool[$poolIndex], $orientation, $x, $y);
        if (!$this->rules->canPlace($fleet, $cells)) {
            $this->invalidMove('error.battleships.invalid_placement');
        }

        $this->placeShip($state, $playerId, $fleet, $cells, 's'.$poolIndex);
        $state->data['placed'][$playerId][$poolIndex] = true;
        ++$state->data['placedCount'][$playerId];

        $this->maybeMarkReady($state, $playerId);
    }

    private function randomize(GameState $state, string $playerId): void
    {
        $this->assertPlacing($state, $playerId);

        $pool = $state->data['shapePool'];
        $width = $state->data['fleets'][$playerId]->width;
        $height = $state->data['fleets'][$playerId]->height;

        $placement = null;
        for ($restart = 0; $restart < self::RANDOMIZE_RESTARTS && null === $placement; ++$restart) {
            $placement = $this->attemptRandomFleet($pool, $width, $height);
        }
        $placement ??= [];

        $fleet = new Board($width, $height);
        foreach ($placement as $index => $cells) {
            $this->placeShip($state, $playerId, $fleet, $cells, 's'.$index);
        }

        $state->data['fleets'][$playerId] = $fleet;
        $state->data['placed'][$playerId] = array_fill_keys(array_keys($placement), true);
        $state->data['placedCount'][$playerId] = \count($placement);

        $this->maybeMarkReady($state, $playerId);
    }

    /**
     * @param list<string> $pool
     *
     * @return array<int, list<array{0: int, 1: int}>>|null index => cells, or null if any ship couldn't be placed
     */
    private function attemptRandomFleet(array $pool, int $width, int $height): ?array
    {
        $fleet = new Board($width, $height);
        $result = [];

        foreach ($pool as $index => $shape) {
            $orientations = $this->rules->orientations($shape);
            $cells = null;
            for ($attempt = 0; $attempt < self::RANDOMIZE_ATTEMPTS_PER_SHIP && null === $cells; ++$attempt) {
                $orientationIndex = array_rand($orientations);
                [$w, $h] = $this->rules->boundingBox($orientations[$orientationIndex]);
                $x = random_int(0, max(0, $width - $w));
                $y = random_int(0, max(0, $height - $h));
                $candidate = $this->rules->shapeCells($shape, $orientationIndex, $x, $y);
                if ($this->rules->canPlace($fleet, $candidate)) {
                    $cells = $candidate;
                }
            }

            if (null === $cells) {
                return null;
            }

            foreach ($cells as [$cx, $cy]) {
                $fleet->place($cx, $cy, new Token(ownerId: 'tmp', variant: 's'.$index));
            }
            $result[$index] = $cells;
        }

        return $result;
    }

    private function reset(GameState $state, string $playerId): void
    {
        $this->assertPlacing($state, $playerId);

        $fleet = $state->data['fleets'][$playerId];
        $state->data['fleets'][$playerId] = new Board($fleet->width, $fleet->height);
        $state->data['placed'][$playerId] = [];
        $state->data['placedCount'][$playerId] = 0;
        $state->logGameEvent('log.battleships.reset', ['%player%' => $state->playerById($playerId)->nickname]);
    }

    /**
     * @param list<array{0: int, 1: int}> $cells
     */
    private function placeShip(GameState $state, string $playerId, Board $fleet, array $cells, string $shipId): void
    {
        $seat = $state->playerById($playerId)->seat;
        [$outer, $center] = GameRules::TOKEN_COLORS[$seat];
        foreach ($cells as [$x, $y]) {
            $fleet->place($x, $y, new Token(ownerId: $playerId, outerColor: $outer, centerColor: $center, variant: $shipId));
        }
    }

    private function maybeMarkReady(GameState $state, string $playerId): void
    {
        if ($state->data['placedCount'][$playerId] < \count($state->data['shapePool'])) {
            return;
        }

        $state->data['ready'][$playerId] = true;
        $state->logGameEvent('log.battleships.ready', ['%player%' => $state->playerById($playerId)->nickname]);

        foreach ($state->data['ready'] as $ready) {
            if (!$ready) {
                return;
            }
        }

        $state->data['phase'] = 'battle';
        $state->currentTurnIndex = 0;
        $state->logGameEvent('log.battleships.battle_started');
    }

    private function fire(GameState $state, string $playerId, int $x, int $y): void
    {
        $opponent = $this->opponent($state, $playerId);
        $fleet = $state->data['fleets'][$opponent->id];
        $shots = $state->data['shots'][$playerId];

        if (!$fleet->inBounds($x, $y) || !$shots->isEmpty($x, $y)) {
            $this->invalidMove('error.battleships.already_fired');
        }

        $target = $fleet->get($x, $y);
        $player = $state->currentPlayer();

        if (null === $target) {
            $shots->place($x, $y, new Token(ownerId: $playerId, outerColor: '#c9d3dc', variant: 'miss'));
            $state->logGameEvent('log.battleships.miss', ['%player%' => $player->nickname]);
            $state->advanceTurn();

            return;
        }

        $shots->place($x, $y, new Token(ownerId: $playerId, outerColor: '#c96b5a', variant: 'hit'));
        $state->data['hits'][$opponent->id][] = "$x:$y";

        $extraTurn = Options::fromState($state)->extraTurnOnHit;
        $state->logGameEvent($extraTurn ? 'log.battleships.hit_again' : 'log.battleships.hit', ['%player%' => $player->nickname]);

        if ($this->rules->isSunk($fleet, $target->variant, $state->data['hits'][$opponent->id])) {
            $state->logGameEvent('log.battleships.sunk', ['%player%' => $player->nickname]);
        }

        if (\count($state->data['hits'][$opponent->id]) >= $state->data['totalCells']) {
            $state->finish($playerId);
            $state->logEvent('log.won', ['%player%' => $player->nickname]);

            return;
        }

        if (!$extraTurn) {
            $state->advanceTurn();
        }
    }

    private function opponent(GameState $state, string $playerId): Player
    {
        return $state->players[0]->id === $playerId ? $state->players[1] : $state->players[0];
    }
}
