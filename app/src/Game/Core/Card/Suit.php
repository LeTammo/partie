<?php

declare(strict_types=1);

namespace App\Game\Core\Card;

// How to use, see
// docs/components/cards.md
enum Suit: string
{
    case Clubs = 'clubs';
    case Spades = 'spades';
    case Hearts = 'hearts';
    case Diamonds = 'diamonds';

    public function symbol(): string
    {
        return match ($this) {
            self::Clubs => '♣',
            self::Spades => '♠',
            self::Hearts => '♥',
            self::Diamonds => '♦',
        };
    }

    public function isRed(): bool
    {
        return self::Hearts === $this || self::Diamonds === $this;
    }
}
