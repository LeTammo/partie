<?php

declare(strict_types=1);

namespace App\Game\Games\Yahtzee;

final class GameRules
{
    public const array UPPER_CATEGORIES = ['ones', 'twos', 'threes', 'fours', 'fives', 'sixes'];

    public const array LOWER_CATEGORIES = [
        'three_of_a_kind', 'four_of_a_kind', 'full_house', 'small_straight', 'large_straight', 'yahtzee', 'chance',
    ];

    public const int UPPER_BONUS_THRESHOLD = 63;
    public const int UPPER_BONUS = 35;

    /** @return list<string> */
    public static function allCategories(): array
    {
        return [...self::UPPER_CATEGORIES, ...self::LOWER_CATEGORIES];
    }

    /**
     * @param list<int> $values
     */
    public function score(string $category, array $values): int
    {
        sort($values);
        $sum = array_sum($values);
        $counts = array_count_values($values);
        $countValues = array_values($counts);
        rsort($countValues);

        return match ($category) {
            'ones', 'twos', 'threes', 'fours', 'fives', 'sixes' => $this->upperScore($category, $counts),
            'three_of_a_kind' => $countValues[0] >= 3 ? $sum : 0,
            'four_of_a_kind' => $countValues[0] >= 4 ? $sum : 0,
            'full_house' => (3 === $countValues[0] && 2 === ($countValues[1] ?? 0)) || 5 === $countValues[0] ? 25 : 0,
            'small_straight' => $this->hasStraight($values, 4) ? 30 : 0,
            'large_straight' => $this->hasStraight($values, 5) ? 40 : 0,
            'yahtzee' => 5 === $countValues[0] ? 50 : 0,
            'chance' => $sum,
            default => throw new \InvalidArgumentException(sprintf('Unknown category "%s".', $category)),
        };
    }

    /**
     * @param array<string, int|null> $scorecard
     */
    public function upperSubtotal(array $scorecard): int
    {
        return (int) array_sum(array_intersect_key($scorecard, array_flip(self::UPPER_CATEGORIES)));
    }

    /**
     * @param array<string, int|null> $scorecard
     */
    public function total(array $scorecard): int
    {
        $upper = $this->upperSubtotal($scorecard);
        $bonus = $upper >= self::UPPER_BONUS_THRESHOLD ? self::UPPER_BONUS : 0;

        return (int) array_sum($scorecard) + $bonus;
    }

    /**
     * @param array<string, int|null> $scorecard
     */
    public function isComplete(array $scorecard): bool
    {
        return array_all(self::allCategories(), fn($category) => isset($scorecard[$category]));
    }

    /**
     * @param array<int, int> $counts value => occurrences
     */
    private function upperScore(string $category, array $counts): int
    {
        $face = array_search($category, self::UPPER_CATEGORIES, true) + 1;

        return $face * ($counts[$face] ?? 0);
    }

    /**
     * @param list<int> $values sorted ascending
     */
    private function hasStraight(array $values, int $length): bool
    {
        $unique = array_values(array_unique($values));
        $run = 1;

        for ($i = 1; $i < \count($unique); ++$i) {
            $run = ($unique[$i] === $unique[$i - 1] + 1) ? $run + 1 : 1;
            if ($run >= $length) {
                return true;
            }
        }

        return $run >= $length;
    }
}
