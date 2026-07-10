<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

// How to use, see
// docs/components/engine-and-state.md
final class Player
{
    public function __construct(
        public readonly string $id,
        public string $nickname,
        public string $color,
        public readonly int $seat,
    ) {
    }
}
