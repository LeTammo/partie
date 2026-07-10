<?php

declare(strict_types=1);

namespace App\Game\Core\Service;

use App\Game\Core\Exception\InvalidMoveException;

// How to use, see
// docs/components/engine-and-state.md
abstract readonly class AbstractGameDefinition implements GameEngineInterface
{
    /**
     * @param array<string, string|int> $params
     */
    protected function invalidMove(string $key, array $params = []): never
    {
        throw new InvalidMoveException($key, $params, domain: $this->getId());
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function intParam(array $payload, string $key, int $default = -1): int
    {
        return isset($payload[$key]) ? (int) $payload[$key] : $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function stringParam(array $payload, string $key, string $default = ''): string
    {
        return isset($payload[$key]) ? (string) $payload[$key] : $default;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<int>
     */
    protected function intListParam(array $payload, string $key): array
    {
        $raw = trim((string) ($payload[$key] ?? ''));
        if ('' === $raw) {
            return [];
        }

        $values = [];
        foreach (explode(',', $raw) as $part) {
            if (is_numeric($part)) {
                $values[] = (int) $part;
            }
        }

        return array_values(array_unique($values));
    }
}
