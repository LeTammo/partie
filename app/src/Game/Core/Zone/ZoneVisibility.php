<?php

declare(strict_types=1);

namespace App\Game\Core\Zone;

// How to use, see
// docs/components/zones-and-tables.md
enum ZoneVisibility: string
{
    /** Contents visible to everyone (a discard pile, melds on the table). */
    case All = 'all';

    /** Contents visible to the owning player only (a hand). */
    case Owner = 'owner';

    /** Contents visible to nobody (a face-down stock). */
    case Hidden = 'hidden';
}
