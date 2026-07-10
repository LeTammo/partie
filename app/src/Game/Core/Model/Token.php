<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

// How to use, see
// docs/components/tokens-and-boards.md
final class Token
{
    public readonly string $id;

    public function __construct(
        public readonly string $ownerId,
        public readonly TokenShape $shape = TokenShape::Round,
        public readonly string $outerColor = '#94a3b8',
        public readonly string $innerColor = '#ffffff',
        public string $variant = '',
    ) {
        $this->id = bin2hex(random_bytes(4));
    }

    public function promote(string $variant): void
    {
        $this->variant = $variant;
    }
}
