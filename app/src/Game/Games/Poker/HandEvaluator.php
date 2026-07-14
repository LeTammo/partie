<?php

declare(strict_types=1);

namespace App\Game\Games\Poker;

use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Rank;

// Best-5-of-7 poker hand evaluator. Poker-specific rule logic (not a
// reusable core mechanic like Gravity/MoveMap), so it stays local to this
// game per docs/development_policies.md.
final class HandEvaluator
{
    /**
     * @param list<PlayingCard> $cards exactly 7 cards (2 hole + 5 community)
     *
     * @return list<int> comparable hand strength: [category, tiebreak...] - higher is better
     */
    public function best(array $cards): array
    {
        $best = null;
        foreach ($this->combinations($cards, 5) as $five) {
            $rank = $this->rankFive($five);
            if (null === $best || $this->compare($rank, $best) > 0) {
                $best = $rank;
            }
        }

        return $best ?? [0, 0, 0, 0, 0, 0];
    }

    public function compare(array $a, array $b): int
    {
        foreach ($a as $i => $value) {
            $cmp = $value <=> ($b[$i] ?? 0);
            if (0 !== $cmp) {
                return $cmp;
            }
        }

        return 0;
    }

    /**
     * @param list<PlayingCard> $five
     *
     * @return list<int> [category, t1, t2, t3, t4, t5]
     */
    private function rankFive(array $five): array
    {
        $ranks = array_map(static fn (PlayingCard $c): int => $c->rank->value, $five);
        rsort($ranks);
        $suits = array_map(static fn (PlayingCard $c): string => $c->suit->value, $five);
        $isFlush = 1 === \count(array_unique($suits));

        $counts = array_count_values($ranks);
        arsort($counts); // by count desc, ties keep rank-desc order from $ranks insertion... normalize below
        $groups = [];
        foreach ($counts as $rank => $count) {
            $groups[] = [$rank, $count];
        }
        usort($groups, static fn (array $a, array $b): int => $b[1] <=> $a[1] ?: $b[0] <=> $a[0]);

        $straightHigh = $this->straightHigh($ranks);

        if (null !== $straightHigh && $isFlush) {
            return [8, $straightHigh, 0, 0, 0, 0];
        }
        if (4 === $groups[0][1]) {
            return [7, $groups[0][0], $groups[1][0], 0, 0, 0];
        }
        if (3 === $groups[0][1] && 2 === $groups[1][1]) {
            return [6, $groups[0][0], $groups[1][0], 0, 0, 0];
        }
        if ($isFlush) {
            return [5, ...$ranks];
        }
        if (null !== $straightHigh) {
            return [4, $straightHigh, 0, 0, 0, 0];
        }
        if (3 === $groups[0][1]) {
            $kickers = array_slice(array_map(static fn (array $g) => $g[0], \array_slice($groups, 1)), 0, 2);

            return [3, $groups[0][0], ...$kickers, 0, 0];
        }
        if (2 === $groups[0][1] && 2 === $groups[1][1]) {
            $kicker = $groups[2][0];

            return [2, $groups[0][0], $groups[1][0], $kicker, 0, 0];
        }
        if (2 === $groups[0][1]) {
            $kickers = array_slice(array_map(static fn (array $g) => $g[0], \array_slice($groups, 1)), 0, 3);

            return [1, $groups[0][0], ...$kickers, 0];
        }

        return [0, ...$ranks];
    }

    /**
     * @param list<int> $ranksDesc
     */
    private function straightHigh(array $ranksDesc): ?int
    {
        $unique = array_values(array_unique($ranksDesc));
        // wheel: A-2-3-4-5 (ace plays low, straight high = 5)
        if (\in_array(Rank::Ace->value, $unique, true)) {
            $withLowAce = $unique;
            $withLowAce[] = 1;
            rsort($withLowAce);
            $unique = $withLowAce;
        }

        $run = 1;
        for ($i = 0; $i < \count($unique) - 1; ++$i) {
            if ($unique[$i] - 1 === $unique[$i + 1]) {
                ++$run;
                if ($run >= 5) {
                    return $unique[$i - 3];
                }
            } else {
                $run = 1;
            }
        }

        return null;
    }

    /**
     * @param list<PlayingCard> $cards
     *
     * @return iterable<list<PlayingCard>>
     */
    private function combinations(array $cards, int $size): iterable
    {
        $n = \count($cards);
        if ($size > $n) {
            return;
        }
        $indexes = range(0, $size - 1);
        while (true) {
            yield array_map(static fn (int $i) => $cards[$i], $indexes);

            $i = $size - 1;
            while ($i >= 0 && $indexes[$i] === $i + $n - $size) {
                --$i;
            }
            if ($i < 0) {
                return;
            }
            ++$indexes[$i];
            for ($j = $i + 1; $j < $size; ++$j) {
                $indexes[$j] = $indexes[$j - 1] + 1;
            }
        }
    }
}
