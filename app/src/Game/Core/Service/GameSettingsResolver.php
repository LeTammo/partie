<?php

declare(strict_types=1);

namespace App\Game\Core\Service;

use App\Game\Core\Model\GameSetting;

// How to use, see
// docs/components/engine-and-state.md
final class GameSettingsResolver
{
    /**
     * @param list<GameSetting> $schema
     * @param array<string, mixed> $raw
     *
     * @return array<string, string|int|bool>
     */
    public static function resolve(array $schema, array $raw): array
    {
        $resolved = [];
        foreach ($schema as $setting) {
            $resolved[$setting->key] = $setting->resolve($raw[$setting->key] ?? null);
        }

        return $resolved;
    }
}
