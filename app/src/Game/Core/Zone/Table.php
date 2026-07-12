<?php

declare(strict_types=1);

namespace App\Game\Core\Zone;

// A collection of named zones - the shared state container for every
// card/table game. Conventional keys: 'stock', 'discard', 'waste',
// 'middle', 'dealer', 'hand:{playerId}', 'meld:{n}', 'foundation:{suit}',
// 'tableau:{n}'.
// How to use, see
// docs/components/zones-and-tables.md
final class Table
{
    /** @var array<string, Zone> insertion order is display order */
    public array $zones = [];

    public function add(Zone $zone): Zone
    {
        $this->zones[$zone->key] = $zone;

        return $zone;
    }

    public function remove(string $key): void
    {
        unset($this->zones[$key]);
    }

    public function zone(string $key): Zone
    {
        return $this->zones[$key] ?? throw new \InvalidArgumentException(sprintf('Unknown zone "%s".', $key));
    }

    public function has(string $key): bool
    {
        return isset($this->zones[$key]);
    }

    /** Shorthand for a player's hand zone. */
    public function hand(string $playerId): Zone
    {
        return $this->zone('hand:'.$playerId);
    }

    /** Move the top $count items from one zone onto another. */
    public function move(string $from, string $to, int $count = 1): void
    {
        $moved = array_splice($this->zone($from)->items, -$count);
        $this->zone($to)->push(...$moved);
    }

    /**
     * All zones whose key starts with the prefix, in insertion order.
     *
     * @return list<Zone>
     */
    public function matching(string $prefix): array
    {
        return array_values(array_filter(
            $this->zones,
            static fn (Zone $zone): bool => str_starts_with($zone->key, $prefix),
        ));
    }
}
