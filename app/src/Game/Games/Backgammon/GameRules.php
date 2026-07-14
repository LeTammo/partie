<?php

declare(strict_types=1);

namespace App\Game\Games\Backgammon;

use App\Game\Core\Model\Token;
use App\Game\Core\Zone\Table;

// Points are numbered 0-23 in one absolute frame. Seat 0 moves 23 -> 0 and
// bears off past 0; seat 1 moves 0 -> 23 and bears off past 23 - mirrored,
// so a checker entering from the bar and a checker bearing off both use the
// same "virtual" position trick: the bar sits just behind point 23 (seat 0)
// or 0 (seat 1), "off" sits just past the far end. No doubling cube.
// homeRange/entryTarget/isOffTarget/canBearOff's exact target are all
// derived from direction() so the two stay in sync if it ever changes.
final class GameRules
{
    public const int POINTS = 24;
    public const int CHECKERS_PER_PLAYER = 15;

    /** @var list<array{0: string, 1: string}> per-seat [outerColor, centerColor] */
    public const array TOKEN_COLORS = [
        ['#a3b8a3', '#c9d8c9'], // sage green - seat 0
        ['#b0a7c7', '#d4cde4'], // soft lavender - seat 1
    ];

    public function direction(int $seat): int
    {
        return 0 === $seat ? -1 : 1;
    }

    /**
     * @return list<int>
     */
    public function homeRange(int $seat): array
    {
        return 1 === $this->direction($seat) ? range(18, 23) : range(0, 5);
    }

    public function isHomePoint(int $seat, int $point): bool
    {
        return \in_array($point, $this->homeRange($seat), true);
    }

    public function entryTarget(int $seat, int $roll): int
    {
        return 1 === $this->direction($seat) ? $roll - 1 : 24 - $roll;
    }

    public function normalTarget(int $seat, int $point, int $roll): int
    {
        return $point + $this->direction($seat) * $roll;
    }

    public function isOffTarget(int $seat, int $target): bool
    {
        return 1 === $this->direction($seat) ? $target > 23 : $target < 0;
    }

    public function ownsPoint(Table $table, string $playerId, int $point): bool
    {
        $items = $table->zone('point:'.$point)->items;

        return [] !== $items && $items[0]->ownerId === $playerId;
    }

    /** A point is open if empty, own, or holds a single ("blot") opposing checker. */
    public function isOpen(Table $table, string $playerId, int $point): bool
    {
        $items = $table->zone('point:'.$point)->items;
        if ([] === $items) {
            return true;
        }

        /** @var Token $first */
        $first = $items[0];

        return $first->ownerId === $playerId || 1 === \count($items);
    }

    public function allCheckersHome(Table $table, string $playerId, int $seat): bool
    {
        if (!$table->zone('bar:'.$playerId)->isEmpty()) {
            return false;
        }
        return array_all(
            range(0, 23),
            fn($point) => $this->isHomePoint($seat, $point) || !$this->ownsPoint($table, $playerId, $point)
        );

    }

    public function canBearOff(Table $table, string $playerId, int $seat, int $point, int $roll): bool
    {
        if (!$this->allCheckersHome($table, $playerId, $seat)) {
            return false;
        }

        $target = $this->normalTarget($seat, $point, $roll);
        if (!$this->isOffTarget($seat, $target)) {
            return false;
        }

        $movesUp = 1 === $this->direction($seat);
        $exact = $movesUp ? 24 : -1;
        if ($target === $exact) {
            return true;
        }

        // overshoot bear-off: legal only if no own checker sits further from the edge
        foreach ($this->homeRange($seat) as $hp) {
            $further = $movesUp ? $hp < $point : $hp > $point;
            if ($further && $this->ownsPoint($table, $playerId, $hp)) {
                return false;
            }
        }

        return true;
    }

    /** The destination zone key for moving from $from with a given $roll, or null if illegal. */
    public function targetZoneFor(Table $table, string $playerId, int $seat, string $from, int $roll): ?string
    {
        $bar = $table->zone('bar:'.$playerId);
        if (!$bar->isEmpty() && $from !== 'bar:'.$playerId) {
            return null; // must enter from the bar first
        }

        if ($from === 'bar:'.$playerId) {
            $target = $this->entryTarget($seat, $roll);

            return $this->isOpen($table, $playerId, $target) ? 'point:'.$target : null;
        }

        if (!str_starts_with($from, 'point:')) {
            return null;
        }

        $point = (int) substr($from, \strlen('point:'));
        if (!$this->ownsPoint($table, $playerId, $point)) {
            return null;
        }

        $target = $this->normalTarget($seat, $point, $roll);
        if ($this->isOffTarget($seat, $target)) {
            return $this->canBearOff($table, $playerId, $seat, $point, $roll) ? 'off:'.$playerId : null;
        }

        return $this->isOpen($table, $playerId, $target) ? 'point:'.$target : null;
    }

    /**
     * @return list<int> board point indexes (or -1 for the bar) $playerId can move from with $roll
     */
    public function legalSourcesForRoll(Table $table, string $playerId, int $seat, int $roll): array
    {
        $sources = [];
        $bar = $table->zone('bar:'.$playerId);
        if (!$bar->isEmpty()) {
            if (null !== $this->targetZoneFor($table, $playerId, $seat, 'bar:'.$playerId, $roll)) {
                $sources[] = 'bar:'.$playerId;
            }

            return $sources;
        }

        foreach (range(0, 23) as $point) {
            if ($this->ownsPoint($table, $playerId, $point)
                && null !== $this->targetZoneFor($table, $playerId, $seat, 'point:'.$point, $roll)) {
                $sources[] = 'point:'.$point;
            }
        }

        return $sources;
    }

    /**
     * @param list<int> $remainingDice
     */
    public function hasAnyLegalMove(Table $table, string $playerId, int $seat, array $remainingDice): bool
    {
        return array_any(
            array_unique($remainingDice),
            fn($roll) => [] !== $this->legalSourcesForRoll($table, $playerId, $seat, $roll)
        );

    }
}
