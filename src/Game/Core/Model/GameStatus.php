<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

enum GameStatus: string
{
    case Waiting = 'waiting';
    case Running = 'running';
    case Finished = 'finished';
}
