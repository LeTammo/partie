<?php

declare(strict_types=1);

namespace App\Game\Games\CrazyEight;

use App\Game\Core\Model\GameState;

// How to use, see
// docs/components/engine-and-state.md
final readonly class Options
{
    public function __construct(
        public bool $stackDraw2,
        public int $startHandSize,
    ) {
    }

    public static function fromState(GameState $state): self
    {
        $settings = $state->data['settings'] ?? [];

        return new self(
            stackDraw2: (bool) ($settings['stackDraw2'] ?? true),
            startHandSize: (int) ($settings['startHandSize'] ?? 7),
        );
    }
}
