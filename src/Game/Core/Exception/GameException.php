<?php

declare(strict_types=1);

namespace App\Game\Core\Exception;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Base exception for when a player makes a mistake.
 *
 * Parameter values prefixed with "t:" are themselves translated first (i.e., for game names);
 * "t:domain:key" translates in another domain than "messages".
 */
class GameException extends \RuntimeException
{
    /**
     * @param array<string, string|int> $params
     * @param string                    $domain translation domain of the key ("messages" or a game id)
     */
    public function __construct(
        string $translationKey,
        private readonly array $params = [],
        private readonly string $domain = 'messages',
    ) {
        parent::__construct($translationKey);
    }

    public function translate(TranslatorInterface $translator): string
    {
        $params = array_map(function ($value) use ($translator) {
            if (!\is_string($value) || !str_starts_with($value, 't:')) {
                return $value;
            }
            [$domain, $key] = str_contains(substr($value, 2), ':')
                ? explode(':', substr($value, 2), 2)
                : ['messages', substr($value, 2)];

            return $translator->trans($key, [], $domain);
        }, $this->params);

        return $translator->trans($this->getMessage(), $params, $this->domain);
    }
}
