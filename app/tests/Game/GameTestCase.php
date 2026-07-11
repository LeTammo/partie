<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Rank;
use App\Game\Core\Card\Suit;
use App\Game\Core\Model\Player;
use PHPUnit\Framework\TestCase;

abstract class GameTestCase extends TestCase
{
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
