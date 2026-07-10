<?php

declare(strict_types=1);

namespace App\Game\Core\Service;

use App\Game\Core\Model\GameSetting;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\Player;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

// How to use, see
// docs/components/engine-and-state.md
#[AutoconfigureTag('app.game')]
interface GameEngineInterface
{
    /** Unique url-safe id, e.g. "tictactoe". */
    public function getId(): string;

    /** Display name, e.g. "Tic-tac-toe". */
    public function getName(): string;

    /** Short tagline shown on the dashboard card. */
    public function getDescription(): string;

    /** Icon name, rendered via templates/icons/{name}.svg.twig. */
    public function getIcon(): string;

    public function getMinPlayers(): int;

    public function getMaxPlayers(): int;

    /** @return list<GameSetting> */
    public function settings(): array;

    /**
     * @param list<Player> $players
     * @param array<string, string|int|bool> $settings
     */
    public function createInitialState(array $players, array $settings = []): GameState;

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \App\Game\Core\Exception\InvalidMoveException
     */
    public function applyMove(GameState $state, string $playerId, array $payload): void;

    public function getTemplate(): string;

    /**
     * @return array<string, mixed>
     */
    public function buildView(GameState $state, ?string $viewerId): array;
}
