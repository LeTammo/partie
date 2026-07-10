<?php

declare(strict_types=1);

namespace App\Game\Core\Service;

use App\Game\Core\Model\Lobby;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class GameBroadcaster
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public static function topicFor(string $code): string
    {
        return 'lobby/'.$code;
    }

    public function broadcast(Lobby $lobby): void
    {
        try {
            $this->hub->publish(new Update(
                self::topicFor($lobby->code),
                '<turbo-stream action="refresh"></turbo-stream>',
            ));
        } catch (\Throwable) {
            // Now the client would need to refresh manually
        }
    }
}
