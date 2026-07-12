<?php

declare(strict_types=1);

namespace App\Game\Core\Zone;

// A named, ordered list of board coordinates - a race track (Ludo's ring),
// a home lane, a row of base slots. `seatStride` rotates the path per seat:
// seat S's progress-N cell is cells[(S * seatStride + N) % length].
// How to use, see
// docs/components/tokens-and-boards.md
final readonly class Path
{
    /**
     * @param list<array{0: int, 1: int}> $cells [x, y] per step
     */
    public function __construct(
        public string $name,
        public array $cells,
        public int $seatStride = 0,
    ) {
    }

    public function length(): int
    {
        return \count($this->cells);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function at(int $index): array
    {
        return $this->cells[$index % $this->length()];
    }

    /** The absolute path index for a seat's progress step. */
    public function indexFor(int $seat, int $progress): int
    {
        return ($seat * $this->seatStride + $progress) % $this->length();
    }

    /**
     * @return array{0: int, 1: int} the [x, y] cell for a seat's progress step
     */
    public function cellFor(int $seat, int $progress): array
    {
        return $this->at($this->indexFor($seat, $progress));
    }
}
