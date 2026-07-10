<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

enum TokenShape: string
{
    case Round = 'round';
    case Square = 'square';
    case Custom = 'custom';
}
