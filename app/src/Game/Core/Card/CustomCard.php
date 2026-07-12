<?php

declare(strict_types=1);

namespace App\Game\Core\Card;

final readonly class CustomCard
{
    public function __construct(
        public string $color,
        public string $value,
    ) {
    }

    public function identity(): string
    {
        return $this->color . '-' . $this->value;
    }
}
