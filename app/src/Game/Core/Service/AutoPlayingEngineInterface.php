<?php

declare(strict_types=1);

namespace App\Game\Core\Service;

use App\Game\Core\Model\GameState;

// How to use, see
// docs/components/engine-and-state.md
interface AutoPlayingEngineInterface
{
    public function hasAutoStep(GameState $state): bool;

    public function applyAutoStep(GameState $state): void;
}
