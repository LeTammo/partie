<?php

declare(strict_types=1);

namespace App\Controller;

use App\Game\Core\Service\GameRegistry;
use App\Game\Core\Service\LobbyManager;
use App\Game\Core\Service\PlayerSession;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(GameRegistry $games, LobbyManager $lobbyManager, PlayerSession $playerSession): Response
    {
        return $this->render('home/index.html.twig', [
            'games' => $games->all(),
            'lobbies' => $lobbyManager->listOpen(),
            'nickname' => $playerSession->nickname(),
        ]);
    }

    #[Route('/nickname', name: 'app_nickname_update', methods: ['POST'])]
    public function updateNickname(Request $request, PlayerSession $playerSession): Response
    {
        if (!$this->isCsrfTokenValid('nickname', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $nickname = trim((string) $request->request->get('nickname', ''));
        if ('' !== $nickname && mb_strlen($nickname) <= 26) {
            $playerSession->setNickname($nickname);
        }

        if ($request->isXmlHttpRequest()) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->redirectToRoute('app_home');
    }
}
