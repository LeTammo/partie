<?php

declare(strict_types=1);

namespace App\Game\Core\View;

// The legal-moves map consumed by the dragdrop--piece-move controller:
// opaque source key => list of legal destination zone keys.
// How to use, see docs/components/tokens-and-boards.md
final class MoveMap
{
    /** @var array<string, list<string>> */
    private array $moves = [];

    public static function cellKey(int $x, int $y): string
    {
        return "cell:$x:$y";
    }

    /**
     * @return array{int, int}|null the trailing ":x:y" coordinates of a key
     */
    public static function coordsOf(string $key): ?array
    {
        if (!preg_match('/^(?:.*:)?(-?\d+):(-?\d+)$/', $key, $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2]];
    }

    public function add(string $source, string ...$destinations): self
    {
        foreach ($destinations as $destination) {
            $this->moves[$source][] = $destination;
        }

        return $this;
    }

    public function has(string $source): bool
    {
        return isset($this->moves[$source]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->moves;
    }
}
