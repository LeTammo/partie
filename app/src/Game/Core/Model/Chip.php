<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

// How to use, see
// docs/components/chips.md
final readonly class Chip
{
    public function __construct(
        public string $color,
        public ?string $label = null,
        public int $value = 0,
    ) {
    }
}
