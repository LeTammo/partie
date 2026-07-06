<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

final class GameState
{
    private const int MAX_LOG_ENTRIES = 30;

    /** @var list<Dice> */
    public array $dice = [];

    /** @var list<Card> */
    public array $cards = [];

    /** @var array<string, mixed> game-specific payload (scorecards, flags, ...) */
    public array $data = [];

    public int $currentTurnIndex = 0;

    public GameStatus $status = GameStatus::Running;

    /** Winner player id, or null (null & Finished = draw). */
    public ?string $winnerId = null;

    public bool $draw = false;

    /**
     * Newest log first. Each entry is a translation key plus parameters, translated at render time
     * in the entry's translation domain; parameter values prefixed with "t:" (or "t:domain:") are
     * translation keys themselves.
     *
     * @var list<array{key: string, domain: string, params: array<string, string|int>}>
     */
    public array $log = [];

    /**
     * @param list<Player> $players in seat order
     */
    public function __construct(
        public readonly string $gameId,
        public array $players,
        public ?Board $board = null,
    ) {
    }

    public function currentPlayer(): Player
    {
        return $this->players[$this->currentTurnIndex];
    }

    public function isPlayersTurn(string $playerId): bool
    {
        return GameStatus::Running === $this->status && $this->currentPlayer()->id === $playerId;
    }

    public function playerById(string $playerId): ?Player
    {
        return array_find($this->players, fn($player) => $player->id === $playerId);
    }

    public function advanceTurn(): void
    {
        $this->currentTurnIndex = ($this->currentTurnIndex + 1) % \count($this->players);
    }

    /**
     * @param array<string, string|int> $params
     * @param string                    $domain translation domain of the key ("messages" or a game id)
     */
    public function logEvent(string $key, array $params = [], string $domain = 'messages'): void
    {
        array_unshift($this->log, ['key' => $key, 'domain' => $domain, 'params' => $params]);
        $this->log = \array_slice($this->log, 0, self::MAX_LOG_ENTRIES);
    }

    /**
     * Logs an event whose key lives in this game's own translation domain.
     *
     * @param array<string, string|int> $params
     */
    public function logGameEvent(string $key, array $params = []): void
    {
        $this->logEvent($key, $params, $this->gameId);
    }

    public function finish(?string $winnerId): void
    {
        $this->status = GameStatus::Finished;
        $this->winnerId = $winnerId;
        $this->draw = null === $winnerId;
    }
}
