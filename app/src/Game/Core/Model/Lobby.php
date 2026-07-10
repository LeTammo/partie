<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

// How to use, see
// docs/components/engine-and-state.md
final class Lobby
{
    /** @var list<Player> */
    public array $players = [];

    public GameStatus $status = GameStatus::Waiting;

    public ?GameState $state = null;

    /** @var array<string, string|int|bool>  */
    public array $settings = [];

    /** @var array<string, int> */
    public array $roundWins = [];

    public int $round = 1;

    /** @var array<string, int> playerId => unix timestamp */
    public array $lastSeen = [];

    public function __construct(
        public readonly string $code,
        public readonly string $gameId,
        public readonly string $hostId,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public function addPlayer(Player $player): void
    {
        $this->players[] = $player;
    }

    public function hasPlayer(string $playerId): bool
    {
        return array_any($this->players, fn($player) => $player->id === $playerId);
    }

    public function playerById(string $playerId): ?Player
    {
        return array_find($this->players, fn($player) => $player->id === $playerId);
    }

    public function isHost(string $playerId): bool
    {
        return $this->hostId === $playerId;
    }
}
