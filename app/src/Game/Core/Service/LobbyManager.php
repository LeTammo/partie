<?php

declare(strict_types=1);

namespace App\Game\Core\Service;

use App\Game\Core\Exception\GameException;
use App\Game\Core\Exception\LobbyNotFoundException;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Lobby;
use App\Game\Core\Model\Player;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * Stores lobbies in a filesystem cache pool, keyed by the 6-character invite code.
 */
final class LobbyManager
{
    private const string CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const int CODE_LENGTH = 6;
    private const int|float TTL_SECONDS = 60 * 60 * 12; // lobbies live for 12h
    private const int MAX_NICKNAME_LENGTH = 26;
    private const string INDEX_KEY = 'lobby_index';

    private const array PLAYER_COLORS = ['#8fb3d9', '#9fbf9f', '#d9a08f', '#c9a9d9', '#d9c98f', '#8fd9cb'];

    private readonly FilesystemAdapter $cache;

    public function __construct(
        private readonly GameRegistry $games,
        string $cacheDir = '',
    ) {
        $this->cache = new FilesystemAdapter(
            namespace: 'lobbies',
            defaultLifetime: self::TTL_SECONDS,
            directory: $cacheDir ?: null
        );
    }

    public function createLobby(string $gameId, string $nickname): array
    {
        $game = $this->games->get($gameId);

        do {
            $code = $this->generateCode();
        } while ($this->exists($code));

        $host = $this->createPlayer($nickname, 0);
        $lobby = new Lobby($code, $game->getId(), $host->id);
        $lobby->settings = GameSettingsResolver::resolve($game->settings(), []);
        $lobby->addPlayer($host);

        $this->save($lobby);
        $this->addToIndex($code);

        return [$lobby, $host];
    }

    public function joinLobby(string $code, string $nickname): array
    {
        $lobby = $this->getLobby($code);
        $game = $this->games->get($lobby->gameId);

        if (GameStatus::Waiting !== $lobby->status) {
            throw new GameException('error.match_started');
        }
        if (\count($lobby->players) >= $game->getMaxPlayers()) {
            throw new GameException('error.lobby_full');
        }

        $player = $this->createPlayer($nickname, \count($lobby->players));
        $lobby->addPlayer($player);
        $this->save($lobby);

        return [$lobby, $player];
    }

    public function startGame(Lobby $lobby, string $playerId): void
    {
        $game = $this->games->get($lobby->gameId);

        if (!$lobby->isHost($playerId)) {
            throw new GameException('error.host_only');
        }
        if (GameStatus::Waiting !== $lobby->status) {
            throw new GameException('error.already_running');
        }
        if (\count($lobby->players) < $game->getMinPlayers()) {
            throw new GameException('error.min_players', [
                '%game%' => 't:game.'.$game->getId().'.name',
                '%count%' => $game->getMinPlayers(),
            ]);
        }

        $lobby->status = GameStatus::Running;
        $lobby->state = $game->createInitialState($lobby->players, $lobby->settings);
        $lobby->state->logEvent('log.game_started', ['%game%' => 't:game.'.$game->getId().'.name']);

        $this->save($lobby);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public function updateSettings(Lobby $lobby, string $playerId, array $raw): void
    {
        if (!$lobby->isHost($playerId)) {
            throw new GameException('error.host_only');
        }
        if (GameStatus::Waiting !== $lobby->status) {
            throw new GameException('error.already_running');
        }

        $game = $this->games->get($lobby->gameId);
        $lobby->settings = GameSettingsResolver::resolve($game->settings(), $raw);

        $this->save($lobby);
    }

    public function playAgain(Lobby $lobby, string $playerId): void
    {
        if (!$lobby->isHost($playerId)) {
            throw new GameException('error.host_only');
        }
        if (GameStatus::Finished !== $lobby->status) {
            throw new GameException('error.not_finished');
        }

        $game = $this->games->get($lobby->gameId);

        ++$lobby->round;
        $lobby->status = GameStatus::Running;
        $lobby->state = $game->createInitialState($lobby->players, $lobby->settings);
        $lobby->state->logEvent('log.game_started', ['%game%' => 't:game.'.$game->getId().'.name']);

        $this->save($lobby);
    }

    public function recordRoundResult(Lobby $lobby): void
    {
        $winnerId = $lobby->state?->winnerId;
        if (null === $winnerId) {
            return;
        }

        $lobby->roundWins[$winnerId] = ($lobby->roundWins[$winnerId] ?? 0) + 1;
    }

    public function getLobby(string $code): Lobby
    {
        $code = strtoupper(trim($code));
        $item = $this->cache->getItem($this->cacheKey($code));

        if (!$item->isHit() || !$item->get() instanceof Lobby) {
            throw new LobbyNotFoundException('error.lobby_not_found', ['%code%' => $code]);
        }

        return $item->get();
    }

    public function exists(string $code): bool
    {
        return $this->cache->getItem($this->cacheKey($code))->isHit();
    }

    /**
     * @return list<Lobby>
     */
    public function listOpen(): array
    {
        $item = $this->cache->getItem(self::INDEX_KEY);
        $codes = $item->isHit() ? $item->get() : [];

        $stillValid = [];
        $open = [];
        foreach ($codes as $code) {
            if (!$this->exists($code)) {
                continue;
            }
            $stillValid[] = $code;

            $lobby = $this->getLobby($code);
            if (GameStatus::Waiting === $lobby->status) {
                $open[] = $lobby;
            }
        }

        if ($stillValid !== $codes) {
            $item->set($stillValid);
            $item->expiresAfter(self::TTL_SECONDS);
            $this->cache->save($item);
        }

        return $open;
    }

    public function save(Lobby $lobby): void
    {
        $item = $this->cache->getItem($this->cacheKey($lobby->code));
        $item->set($lobby);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    public function delete(string $code): void
    {
        $this->cache->deleteItem($this->cacheKey(strtoupper($code)));
    }

    private function cacheKey(string $code): string
    {
        return 'lobby_'.$code;
    }

    private function addToIndex(string $code): void
    {
        $item = $this->cache->getItem(self::INDEX_KEY);
        $codes = $item->isHit() ? $item->get() : [];

        if (!\in_array($code, $codes, true)) {
            $codes[] = $code;
        }

        $item->set($codes);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    private function generateCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; ++$i) {
            $code .= self::CODE_ALPHABET[random_int(0, \strlen(self::CODE_ALPHABET) - 1)];
        }

        return $code;
    }

    private function createPlayer(string $nickname, int $seat): Player
    {
        $nickname = trim($nickname);
        if ('' === $nickname || mb_strlen($nickname) > self::MAX_NICKNAME_LENGTH) {
            throw new GameException('error.nickname_length');
        }

        return new Player(
            id: bin2hex(random_bytes(16)),
            nickname: $nickname,
            color: self::PLAYER_COLORS[$seat % \count(self::PLAYER_COLORS)],
            seat: $seat,
        );
    }
}
