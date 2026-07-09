<?php

declare(strict_types=1);

namespace App\Game\Core\Service;

use App\Game\Core\Model\GameState;

/**
 * Games whose state advances on its own after a certain player moves.
 * Applies the steps one by one, broadcasting and pausing in between so everyone can follow along.
 */
interface AutoPlayingEngineInterface
{
    public function hasAutoStep(GameState $state): bool;

    public function applyAutoStep(GameState $state): void;
}
