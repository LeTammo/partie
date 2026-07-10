<?php

declare(strict_types=1);

namespace App\Game\Core\View;

use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Player;

// How to use, see
// docs/components/engine-and-state.md
final class PlayerViews
{
    /**
     * @param \Closure(Player): array<string, mixed>|null $extras
     *
     * @return list<array<string, mixed>>
     */
    public static function build(GameState $state, ?\Closure $extras = null): array
    {
        $current = GameStatus::Running === $state->status ? $state->currentPlayer()->id : null;

        $players = [];
        foreach ($state->players as $player) {
            $base = [
                'nickname' => $player->nickname,
                'color' => $player->color,
                'current' => $current === $player->id,
            ];
            $players[] = null !== $extras ? $extras($player) + $base : $base;
        }

        return $players;
    }
}
