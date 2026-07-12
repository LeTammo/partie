<?php

declare(strict_types=1);

namespace App\Game\Games\ElevenOut;

use App\Game\Core\Card\CustomCard;

final readonly class GameRules
{
    /**
     * @param array<string, array{min: ?int, max: ?int}> $board
     */
    public function playable(CustomCard $card, array $board, ?CustomCard $startingElf): bool
    {
        if ($startingElf !== null) {
            return $card->color === $startingElf->color && $card->value === $startingElf->value;
        }

        $value = (int) $card->value;
        if ($value === 11) {
            return true;
        }

        $min = $board[$card->color]['min'] ?? null;
        $max = $board[$card->color]['max'] ?? null;

        if ($min === null || $max === null) {
            return false;
        }

        return $value === ($min - 1) || $value === ($max + 1);
    }

    /**
     * @param array<string, list<CustomCard>> $hands
     */
    public function getStartingElf(array $hands): ?CustomCard
    {
        $priorities = [
            ['color' => 'red', 'val' => '11'],
            ['color' => 'yellow', 'val' => '11'],
            ['color' => 'green', 'val' => '11'],
            ['color' => 'blue', 'val' => '11'],
        ];

        foreach ($priorities as $p) {
            foreach ($hands as $hand) {
                foreach ($hand as $card) {
                    if ($card->color === $p['color'] && $card->value === $p['val']) {
                        return $card;
                    }
                }
            }
        }

        return null;
    }
}
