<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

// How to use, see
// docs/components/engine-and-state.md
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

    /** @var list<array{key: string, domain: string, seq: int, params: array<string, string|int>}> */
    public array $log = [];

    private int $logSeq = 0;

    /** @param list<Player> $players in seat order */
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

    public function isViewersTurn(?string $viewerId): bool
    {
        return null !== $viewerId && $this->isPlayersTurn($viewerId);
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
     * @param string $key
     * @param array<string, string|int> $params
     * @param string $domain
     */
    public function logEvent(string $key, array $params = [], string $domain = 'messages'): void
    {
        array_unshift($this->log, ['key' => $key, 'domain' => $domain, 'seq' => ++$this->logSeq, 'params' => $params]);
        $this->log = \array_slice($this->log, 0, self::MAX_LOG_ENTRIES);
    }

    /** @param array<string, string|int> $params */
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
