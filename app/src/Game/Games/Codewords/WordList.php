<?php

declare(strict_types=1);

namespace App\Game\Games\Codewords;

final readonly class WordList
{
    public function __construct(private string $wordsDir)
    {
    }

    /**
     * @return list<string>
     */
    public function pick(string $listKey, int $count): array
    {
        /** @var list<string> $words */
        $words = json_decode(file_get_contents($this->wordsDir.'/'.$listKey.'.json'), true);
        shuffle($words);

        return array_slice($words, 0, $count);
    }
}
