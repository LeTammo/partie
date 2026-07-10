<?php

declare(strict_types=1);

namespace App\Game\Core\Service;

use App\Game\Core\Model\Lobby;
use App\Game\Core\Model\Player;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PlayerSession
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function remember(string $lobbyCode, Player $player): void
    {
        $session = $this->requestStack->getSession();
        $session->set($this->key($lobbyCode), $player->id);
        $session->set('nickname', $player->nickname);
    }

    public function playerIdFor(string $lobbyCode): ?string
    {
        return $this->requestStack->getSession()->get($this->key($lobbyCode));
    }

    public function playerFor(Lobby $lobby): ?Player
    {
        $playerId = $this->playerIdFor($lobby->code);

        return null !== $playerId ? $lobby->playerById($playerId) : null;
    }

    public function nickname(): string
    {
        return (string) $this->requestStack->getSession()->get('nickname', '');
    }

    public function setNickname(string $nickname): void
    {
        $this->requestStack->getSession()->set('nickname', trim($nickname));
    }

    private function key(string $lobbyCode): string
    {
        return 'lobby_player_'.strtoupper($lobbyCode);
    }
}
