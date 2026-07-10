<?php

declare(strict_types=1);

namespace App\Game\Core\Card;

// How to use, see
// docs/components/cards.md
final readonly class PlayingCard
{
    private function __construct(
        public ?Suit $suit,
        public ?Rank $rank,
        public bool $joker,
    ) {
    }

    public static function of(Suit $suit, Rank $rank): self
    {
        return new self($suit, $rank, false);
    }

    public static function jokerCard(): self
    {
        return new self(null, null, true);
    }

    public function is(Suit $suit, Rank $rank): bool
    {
        return !$this->joker && $this->suit === $suit && $this->rank === $rank;
    }
}
