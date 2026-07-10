<?php

declare(strict_types=1);

namespace App\Game\Games\MauMau;

use App\Game\Core\Card\Rank;
use App\Game\Core\Model\GameState;

// How to use, see
// docs/components/engine-and-state.md
final readonly class Options
{
    public function __construct(
        public Rank $skipRank,
        public Rank $drawRank,
        public bool $stackDraw,
        public bool $stackSkip,
        public bool $allowRewish,
    ) {
    }

    public static function fromState(GameState $state): self
    {
        $settings = $state->data['settings'] ?? [];

        return new self(
            skipRank: Rank::from((int) ($settings['skipRank'] ?? Rank::Eight->value)),
            drawRank: Rank::from((int) ($settings['drawRank'] ?? Rank::Seven->value)),
            stackDraw: (bool) ($settings['stackDraw'] ?? true),
            stackSkip: (bool) ($settings['stackSkip'] ?? false),
            allowRewish: (bool) ($settings['allowRewish'] ?? false),
        );
    }
}
