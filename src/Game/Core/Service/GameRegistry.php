<?php

declare(strict_types=1);

namespace App\Game\Core\Service;

use App\Game\Core\Exception\GameException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class GameRegistry
{
    /** @var array<string, GameEngineInterface> */
    private array $games = [];

    /**
     * @param iterable<GameEngineInterface> $games
     */
    public function __construct(
        #[AutowireIterator('app.game')]
        iterable $games,
    ) {
        foreach ($games as $game) {
            $this->games[$game->getId()] = $game;
        }
    }

    /**
     * @return array<string, GameEngineInterface>
     */
    public function all(): array
    {
        return $this->games;
    }

    public function get(string $id): GameEngineInterface
    {
        return $this->games[$id] ?? throw new GameException('error.unknown_game');
    }

    public function has(string $id): bool
    {
        return isset($this->games[$id]);
    }
}
