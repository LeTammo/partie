<?php

declare(strict_types=1);

namespace App\Game\Games\Durak;

use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Suit;

final class GameRules
{
    public const int HAND_SIZE = 6;
    public const int MAX_ATTACK_CARDS = 6;

    public function beats(PlayingCard $defend, PlayingCard $attack, Suit $trumpSuit): bool
    {
        if ($defend->suit === $attack->suit) {
            return $defend->rank->value > $attack->rank->value;
        }

        return $defend->suit === $trumpSuit && $attack->suit !== $trumpSuit;
    }

    /**
     * @param list<array{attack: PlayingCard, defend: ?PlayingCard}> $pairs
     */
    public function canAttackWith(PlayingCard $card, array $pairs): bool
    {
        if ([] === $pairs) {
            return true;
        }

        foreach ($pairs as $pair) {
            if ($card->rank === $pair['attack']->rank || ($pair['defend'] && $card->rank === $pair['defend']->rank)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{attack: PlayingCard, defend: ?PlayingCard}> $pairs
     */
    public function allDefended(array $pairs): bool
    {
        foreach ($pairs as $pair) {
            if (null === $pair['defend']) {
                return false;
            }
        }

        return [] !== $pairs;
    }
}
