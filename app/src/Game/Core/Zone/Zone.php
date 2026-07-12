<?php

declare(strict_types=1);

namespace App\Game\Core\Zone;

// A named location on a table that holds pieces (cards, tokens, chips).
// Zones are dumb containers - legality of moving between them stays in
// each game's applyMove().
// How to use, see
// docs/components/zones-and-tables.md
final class Zone
{
    /** @var list<mixed> the contained pieces, bottom to top */
    public array $items = [];

    /** @var array<string, mixed> free-form game metadata (a meld's type, ...) */
    public array $meta = [];

    public function __construct(
        public readonly string $key,
        public readonly ?string $ownerId = null,
        public ZoneVisibility $visibility = ZoneVisibility::All,
    ) {
    }

    public function push(mixed ...$items): void
    {
        foreach ($items as $item) {
            $this->items[] = $item;
        }
    }

    public function pop(): mixed
    {
        return array_pop($this->items);
    }

    public function top(): mixed
    {
        return [] !== $this->items ? $this->items[array_key_last($this->items)] : null;
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }

    public function removeAt(int $index): mixed
    {
        $removed = array_splice($this->items, $index, 1);

        return $removed[0] ?? null;
    }

    /**
     * Remove and return every item from $index to the top (a run).
     *
     * @return list<mixed>
     */
    public function takeFrom(int $index): array
    {
        return array_splice($this->items, $index);
    }

    /**
     * @return list<mixed>
     */
    public function clear(): array
    {
        $items = $this->items;
        $this->items = [];

        return $items;
    }

    public function visibleTo(?string $viewerId): bool
    {
        return match ($this->visibility) {
            ZoneVisibility::All => true,
            ZoneVisibility::Owner => null !== $viewerId && $viewerId === $this->ownerId,
            ZoneVisibility::Hidden => false,
        };
    }
}
