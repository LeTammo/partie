<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

// How to use, see
// docs/components/engine-and-state.md
final readonly class GameSetting
{
    /**
     * @param array<string, string>|null              $options      value (as string) => translation key; required for Enum
     * @param list<array{0: int, 1: int}>|null         $previewCells a shape's cells, rendered as a small grid next to the label
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public GameSettingType $type,
        public string|int|bool $default,
        public ?array $options = null,
        public ?int $min = null,
        public ?int $max = null,
        public ?array $previewCells = null,
    ) {
    }

    public function resolve(mixed $raw): string|int|bool
    {
        return match ($this->type) {
            GameSettingType::Bool => null !== $raw ? \in_array((string) $raw, ['1', 'true', 'on'], true) : (bool) $this->default,
            GameSettingType::Int => $this->resolveInt($raw),
            GameSettingType::Enum => (null !== $raw && isset($this->options[(string) $raw])) ? (string) $raw : (string) $this->default,
        };
    }

    private function resolveInt(mixed $raw): int
    {
        if (null === $raw || !is_numeric($raw)) {
            return (int) $this->default;
        }

        $value = (int) $raw;
        if (null !== $this->min) {
            $value = max($this->min, $value);
        }
        if (null !== $this->max) {
            $value = min($this->max, $value);
        }

        return $value;
    }
}
