<?php

declare(strict_types=1);

namespace App\Game\Core\Model;

// How to use, see
// docs/components/engine-and-state.md
enum GameSettingType: string
{
    case Bool = 'bool';
    case Int = 'int';
    case Enum = 'enum';
}
