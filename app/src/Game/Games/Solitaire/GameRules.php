<?php

declare(strict_types=1);

namespace App\Game\Games\Solitaire;

use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Rank;

final class GameRules
{
    public const int COLUMNS = 7;

    public function order(Rank $rank): int
    {
        return Rank::Ace === $rank ? 1 : $rank->value;
    }

    public function canStackTableau(PlayingCard $moving, PlayingCard $onto): bool
    {
        return $this->order($onto->rank) === $this->order($moving->rank) + 1
            && $onto->suit->isRed() !== $moving->suit->isRed();
    }

    public function canStackFoundation(PlayingCard $moving, ?PlayingCard $top): bool
    {
        if (null === $top) {
            return Rank::Ace === $moving->rank;
        }

        return $top->suit === $moving->suit && $this->order($moving->rank) === $this->order($top->rank) + 1;
    }

    /**
     * @param list<array{card: PlayingCard, faceUp: bool}> $column
     */
    public function canDropOnTableau(PlayingCard $moving, array $column): bool
    {
        if ([] === $column) {
            return Rank::King === $moving->rank;
        }

        return $this->canStackTableau($moving, $column[array_key_last($column)]['card']);
    }

    /**
     * @param list<PlayingCard> $foundation
     */
    public function canDropOnFoundation(PlayingCard $moving, array $foundation): bool
    {
        return $this->canStackFoundation($moving, [] !== $foundation ? $foundation[array_key_last($foundation)] : null);
    }
}
