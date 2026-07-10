<?php

declare(strict_types=1);

namespace App\Game\Core\Card;

// How to use, see
// docs/components/cards.md
enum Rank: int
{
    case Two = 2;
    case Three = 3;
    case Four = 4;
    case Five = 5;
    case Six = 6;
    case Seven = 7;
    case Eight = 8;
    case Nine = 9;
    case Ten = 10;
    case Jack = 11;
    case Queen = 12;
    case King = 13;
    case Ace = 14;

    public function labelKey(): string
    {
        return match ($this) {
            self::Jack => 'jack',
            self::Queen => 'queen',
            self::King => 'king',
            self::Ace => 'ace',
            default => (string) $this->value,
        };
    }

    public function isFace(): bool
    {
        return $this->value >= self::Jack->value && $this->value <= self::King->value;
    }
}
