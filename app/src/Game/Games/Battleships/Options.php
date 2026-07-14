<?php

declare(strict_types=1);

namespace App\Game\Games\Battleships;

use App\Game\Core\Model\GameState;

// How to use, see
// docs/components/engine-and-state.md
final readonly class Options
{
    /** @var array<string, string> setting key => shape key */
    private const array SHAPE_SETTINGS = [
        'shipsLine2' => 'line2',
        'shipsLine3' => 'line3',
        'shipsLine4' => 'line4',
        'shipsLine5' => 'line5',
        'shipsSquare4' => 'square4',
        'shipsSquare6' => 'square6',
        'shipsL' => 'l',
        'shipsV' => 'v',
        'shipsS4' => 's4',
        'shipsS5' => 's5',
    ];

    /**
     * @param array<string, int> $shapeCounts shape key => count
     */
    public function __construct(
        public array $shapeCounts,
        public int $gridWidth,
        public int $gridHeight,
        public bool $extraTurnOnHit,
    ) {
    }

    public static function fromState(GameState $state): self
    {
        return self::fromSettings($state->data['settings'] ?? []);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function fromSettings(array $settings): self
    {
        $counts = [];
        foreach (self::SHAPE_SETTINGS as $key => $shape) {
            $counts[$shape] = max(0, min(4, (int) ($settings[$key] ?? self::default($key))));
        }

        return new self(
            shapeCounts: $counts,
            gridWidth: max(6, min(16, (int) ($settings['gridWidth'] ?? 10))),
            gridHeight: max(6, min(16, (int) ($settings['gridHeight'] ?? 10))),
            extraTurnOnHit: (bool) ($settings['extraTurnOnHit'] ?? false),
        );
    }

    private static function default(string $settingKey): int
    {
        return match ($settingKey) {
            'shipsLine5' => 1,
            'shipsLine4' => 1,
            'shipsLine3' => 2,
            'shipsLine2' => 1,
            default => 0,
        };
    }
}
