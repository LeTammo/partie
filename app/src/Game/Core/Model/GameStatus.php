<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

// How to use, see
// docs/components/engine-and-state.md
enum GameStatus: string
{
    case Waiting = 'waiting';
    case Running = 'running';
    case Finished = 'finished';
}
