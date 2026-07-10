<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

// How to use, see
// docs/components/tokens-and-boards.md
enum TokenShape: string
{
    case Round = 'round';
    case Square = 'square';
    case Custom = 'custom';
}
