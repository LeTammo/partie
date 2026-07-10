<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

// How to use, see
// docs/components/dice.md
final class Dice
{
    public function __construct(
        public readonly int $maxFaces = 6,
        public int $value = 1,
        public bool $locked = false,
    ) {
    }

    public function roll(): int
    {
        if (!$this->locked) {
            $this->value = random_int(1, $this->maxFaces);
        }

        return $this->value;
    }

    public function toggleLock(): void
    {
        $this->locked = !$this->locked;
    }
}
