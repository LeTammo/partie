<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

// How to use, see
// docs/components/tokens-and-boards.md
final class Token
{
    public readonly string $id;

    /**
     * A generic playing piece with up to three concentric colorable areas
     * (outer disc, middle band, center dot) and an optional symbol/char.
     * Rendering happens in components/token.html.twig; ring, shadow and
     * interactivity are view concerns configured at render time.
     */
    public function __construct(
        public readonly string $ownerId,
        public readonly TokenShape $shape = TokenShape::Round,
        public readonly string $outerColor = '#94a3b8',
        public readonly ?string $middleColor = null,
        public readonly ?string $centerColor = null,
        public readonly ?string $symbol = null,
        public string $variant = '',
    ) {
        $this->id = bin2hex(random_bytes(4));
    }

    public function promote(string $variant): void
    {
        $this->variant = $variant;
    }
}
