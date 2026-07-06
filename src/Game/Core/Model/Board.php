<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

/**
 * Coordinate-based grid (0-indexed, x = column, y = row) holding Tokens.
 */
final class Board
{
    /** @var array<string, Token> keyed by "x:y" */
    private array $cells = [];

    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    private static function key(int $x, int $y): string
    {
        return $x.':'.$y;
    }

    public function inBounds(int $x, int $y): bool
    {
        return $x >= 0 && $x < $this->width && $y >= 0 && $y < $this->height;
    }

    public function get(int $x, int $y): ?Token
    {
        return $this->cells[self::key($x, $y)] ?? null;
    }

    public function isEmpty(int $x, int $y): bool
    {
        return $this->inBounds($x, $y) && null === $this->get($x, $y);
    }

    public function place(int $x, int $y, Token $token): void
    {
        if (!$this->inBounds($x, $y)) {
            throw new \OutOfBoundsException(
                sprintf('Cell %d:%d is outside the %dx%d board.', $x, $y, $this->width, $this->height)
            );
        }
        $this->cells[self::key($x, $y)] = $token;
    }

    public function remove(int $x, int $y): ?Token
    {
        $token = $this->get($x, $y);
        unset($this->cells[self::key($x, $y)]);

        return $token;
    }

    public function move(int $fromX, int $fromY, int $toX, int $toY): void
    {
        $token = $this->remove($fromX, $fromY);
        if (null === $token) {
            throw new \LogicException(sprintf('No token at %d:%d to move.', $fromX, $fromY));
        }
        $this->place($toX, $toY, $token);
    }

    /**
     * @return list<array{x: int, y: int, token: Token}>
     */
    public function tokens(): array
    {
        $result = [];
        foreach ($this->cells as $key => $token) {
            [$x, $y] = array_map(intval(...), explode(':', $key));
            $result[] = ['x' => $x, 'y' => $y, 'token' => $token];
        }

        return $result;
    }

    public function countTokensOf(string $ownerId): int
    {
        return \count(array_filter($this->cells, static fn (Token $t): bool => $t->ownerId === $ownerId));
    }
}
