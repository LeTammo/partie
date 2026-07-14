<?php

declare(strict_types=1);

namespace App\Game\Games\Ludo;

use App\Game\Core\Model\GameState;

// How to use, see
// docs/components/engine-and-state.md
final readonly class Options
{
    public const string REROLL_NO_LEGAL_MOVE = 'no_legal_move';
    public const string REROLL_NO_OPEN_FIELD = 'no_open_field';

    public function __construct(
        public bool $startOneReleased,
        public bool $enforceStartClearingWhilePawnInBase,
        public bool $allowGoalStretchOvertaking,
        public bool $threeSixesPenalty,
        public string $rerollRule,
    ) {
    }

    public static function fromState(GameState $state): self
    {
        $settings = $state->data['settings'] ?? [];

        return new self(
            startOneReleased: (bool) ($settings['startOneReleased'] ?? true),
            enforceStartClearingWhilePawnInBase: (bool) ($settings['enforceStartClearingWhilePawnInBase'] ?? true),
            allowGoalStretchOvertaking: (bool) ($settings['allowGoalStretchOvertaking'] ?? false),
            threeSixesPenalty: (bool) ($settings['threeSixesPenalty'] ?? true),
            rerollRule: (string) ($settings['rerollRule'] ?? self::REROLL_NO_LEGAL_MOVE),
        );
    }
}
