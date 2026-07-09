<?php

declare(strict_types=1);

namespace App\Game\Core\Service;

use App\Game\Core\Exception\InvalidMoveException;

/**
 * Optional base for GameEngineInterface implementations. Games are free to
 * implement the interface directly instead; this only exists to remove the
 * repeated `domain: $this->getId()` argument every game's GameDefinition
 * otherwise has to type out at each `throw new InvalidMoveException(...)`
 * call site.
 */
abstract readonly class AbstractGameDefinition implements GameEngineInterface
{
    /**
     * @param array<string, string|int> $params
     */
    protected function invalidMove(string $key, array $params = []): never
    {
        throw new InvalidMoveException($key, $params, domain: $this->getId());
    }
}
