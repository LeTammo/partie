<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

/**
 * A card layout with configurable segments.
 *
 * Segments are free-form named regions where symbols or values can be
 * displayed, e.g. "center", "border", "corner-tl", "corner-br".
 */
final readonly class Card
{
    /**
     * @param array<string, string|int> $segments segment name => symbol/value
     */
    public function __construct(
        public string $id,
        public array $segments = [],
        public bool $faceUp = true,
    ) {
    }

    public function segment(string $name): string|int|null
    {
        return $this->segments[$name] ?? null;
    }

    public function with(array $segments): self
    {
        return new self($this->id, [...$this->segments, ...$segments], $this->faceUp);
    }

    public function flipped(): self
    {
        return new self($this->id, $this->segments, !$this->faceUp);
    }
}
