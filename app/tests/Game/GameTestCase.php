<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Rank;
use App\Game\Core\Card\Suit;
use App\Game\Core\Model\Player;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorTrait;

abstract class GameTestCase extends TestCase
{
    protected static function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            use TranslatorTrait;
        };
    }

    /**
     * @return list<Player>
     */
    protected static function players(int $count): array
    {
        $players = [];
        for ($i = 0; $i < $count; ++$i) {
            $players[] = new Player('p'.$i, 'Player'.$i, '#ffffff', $i);
        }

        return $players;
    }

    protected static function card(Suit $suit, Rank $rank): PlayingCard
    {
        return PlayingCard::of($suit, $rank);
    }
}
