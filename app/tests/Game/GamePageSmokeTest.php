<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Service\GameRegistry;
use App\Game\Core\Service\LobbyManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end render check for every registered game: create a lobby
 * through the real controllers, seat enough players, start the match and
 * render the running game page. Catches renderer/template errors that
 * unit tests and lint:twig cannot see.
 */
final class GamePageSmokeTest extends WebTestCase
{
    public function testEveryGameStartsAndRenders(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $registry = self::getContainer()->get(GameRegistry::class);
        $lobbyManager = self::getContainer()->get(LobbyManager::class);

        foreach ($registry->all() as $game) {
            $code = $this->createLobbyFor($client, $game->getId());
            $lobby = $lobbyManager->getLobby($code);

            for ($i = \count($lobby->players); $i < $game->getMinPlayers(); ++$i) {
                $lobbyManager->joinLobby($code, 'Bot'.$i);
            }

            $crawler = $client->request('GET', '/lobby/'.$code);
            self::assertResponseIsSuccessful($game->getId().': waiting room');

            $startToken = $crawler->filter('form[action$="/start"] input[name="_token"]')->attr('value');
            $client->request('POST', '/lobby/'.$code.'/start', ['_token' => $startToken]);
            $client->followRedirect();
            self::assertResponseIsSuccessful($game->getId().': running game page (host view)');

            // the spectator view has no hand/board interactions but must build too
            $lobby = $lobbyManager->getLobby($code);
            self::assertIsArray($game->buildView($lobby->state, null), $game->getId().': spectator view');

            $lobbyManager->delete($code);
        }
    }

    private function createLobbyFor(KernelBrowser $client, string $gameId): string
    {
        $crawler = $client->request('GET', '/');
        $token = $crawler->filter('form[action$="/lobby/create"] input[name="_token"]')->attr('value');

        $client->request('POST', '/lobby/create', [
            '_token' => $token,
            'game' => $gameId,
            'nickname' => 'Host',
        ]);

        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/lobby/[A-Z0-9]{6}$#', $location, $gameId.': lobby created');

        return substr($location, -6);
    }
}
