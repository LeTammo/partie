<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

/**
 * A generic game piece. Games configure shape and colors and may attach
 * a free-form "variant" (e.g. "king" in Checkers, "x"/"o" in Tic-tac-toe).
 */
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
