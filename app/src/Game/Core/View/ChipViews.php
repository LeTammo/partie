<?php

declare(strict_types=1);

namespace App\Game\Core\View;

use App\Game\Core\Model\Chip;

// How to use, see
// docs/components/chips.md
final class ChipViews
{
    /** Denomination => chip color, largest first. */
    private const array DENOMINATIONS = [
        100 => 'var(--color-warmgray-700)',
        50 => 'var(--color-softblue-500)',
        20 => 'var(--color-sage-500)',
        10 => 'var(--color-terracotta-500)',
        5 => 'var(--color-sunny-500)',
        1 => 'var(--color-warmgray-400)',
    ];

    public static function single(int $value): Chip
    {
        $color = self::DENOMINATIONS[$value] ?? 'var(--color-warmgray-400)';

        return new Chip($color, (string) $value, $value);
    }

    /**
     * Break an amount into denomination chips, largest first.
     *
     * @return list<Chip>
     */
    public static function stack(int $amount, int $maxChips = 8): array
    {
        $chips = [];
        foreach (self::DENOMINATIONS as $value => $color) {
            while ($amount >= $value && \count($chips) < $maxChips) {
                $chips[] = new Chip($color, (string) $value, $value);
                $amount -= $value;
            }
        }

        return $chips;
    }
}
