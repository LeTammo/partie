<?php

declare(strict_types=1);

namespace App\Game\Core\Card;

// How to use, see
// docs/components/cards.md
final class DeckFactory
{
    /**
     * 32-card deck (7 to Ace).
     *
     * @return list<PlayingCard>
     */
    public static function deck32(): array
    {
        return self::build(minRank: Rank::Seven, jokers: 0, copies: 1);
    }

    /**
     * 52-card deck (2 to Ace).
     *
     * @return list<PlayingCard>
     */
    public static function deck52(): array
    {
        return self::build(minRank: Rank::Two, jokers: 0, copies: 1);
    }

    /**
     * 55-card deck (2 to Ace + 3 jokers).
     *
     * @return list<PlayingCard>
     */
    public static function deck55(): array
    {
        return self::build(minRank: Rank::Two, jokers: 3, copies: 1);
    }

    /**
     * 110-card deck (twice 2 to Ace + 6 jokers).
     *
     * @return list<PlayingCard>
     */
    public static function deck110(): array
    {
        return self::build(minRank: Rank::Two, jokers: 6, copies: 2);
    }

    /**
     * @return list<PlayingCard>
     */
    private static function build(Rank $minRank, int $jokers, int $copies, bool $shuffle = true): array
    {
        $deck = [];
        for ($i = 0; $i < $copies; ++$i) {
            foreach (Suit::cases() as $suit) {
                foreach (Rank::cases() as $rank) {
                    if ($rank->value >= $minRank->value) {
                        $deck[] = PlayingCard::of($suit, $rank);
                    }
                }
            }
        }

        for ($i = 0; $i < $jokers; ++$i) {
            $deck[] = PlayingCard::jokerCard();
        }

        if ($shuffle) {
            shuffle($deck);
        }

        return $deck;
    }
}
