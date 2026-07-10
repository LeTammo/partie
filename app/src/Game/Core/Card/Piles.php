<?php

declare(strict_types=1);

namespace App\Game\Core\Card;

// How to use, see
// docs/components/cards.md
final class Piles
{
    /**
     * @param list<PlayingCard> $pile
     * @param list<PlayingCard> $discard
     *
     * @return list<PlayingCard>
     */
    public static function draw(array &$pile, array &$discard, int $count = 1, ?\Closure $onReshuffle = null): array
    {
        $drawn = [];
        for ($i = 0; $i < $count; ++$i) {
            if ([] === $pile) {
                if (!self::reshuffle($pile, $discard)) {
                    break;
                }
                if (null !== $onReshuffle) {
                    $onReshuffle();
                }
            }
            $drawn[] = array_pop($pile);
        }

        return $drawn;
    }

    /**
     * @param list<PlayingCard> $pile
     * @param list<PlayingCard> $discard
     */
    private static function reshuffle(array &$pile, array &$discard): bool
    {
        if (\count($discard) <= 1) {
            return false;
        }

        $top = array_pop($discard);
        shuffle($discard);
        $pile = $discard;
        $discard = [$top];

        return true;
    }
}
