<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

// How to use, see
// docs/components/dice.md
final class Dice
{
    public readonly int $maxFaces;

    /**
     * @param list<int|string>|null $faces custom face symbols (chars or numbers),
     *                                     face N shows $faces[N-1]; null renders classic pips
     */
    public function __construct(
        int $maxFaces = 6,
        public int $value = 1,
        public bool $locked = false,
        public readonly ?array $faces = null,
    ) {
        $this->maxFaces = null !== $faces ? \count($faces) : $maxFaces;
    }

    public function roll(): int
    {
        if (!$this->locked) {
            $this->value = random_int(1, $this->maxFaces);
        }

        return $this->value;
    }

    /** The symbol to display for the current value. */
    public function face(): int|string
    {
        return $this->faces[$this->value - 1] ?? $this->value;
    }

    public function toggleLock(): void
    {
        $this->locked = !$this->locked;
    }
}
