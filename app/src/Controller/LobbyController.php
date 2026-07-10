<?php

declare(strict_types=1);

namespace App\Controller;

use App\Game\Core\Exception\GameException;
use App\Game\Core\Exception\LobbyNotFoundException;
use App\Game\Core\Service\GameBroadcaster;
use App\Game\Core\Service\GameRegistry;
use App\Game\Core\Service\LobbyManager;
use App\Game\Core\Service\PlayerSession;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/lobby')]
final class LobbyController extends AbstractController
{
    public function __construct(
        private readonly LobbyManager $lobbyManager,
        private readonly GameRegistry $games,
        private readonly PlayerSession $playerSession,
        private readonly GameBroadcaster $broadcaster,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/create', name: 'app_lobby_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('lobby', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $gameId = (string) $request->request->get('game', '');
        $nickname = trim((string) $request->request->get('nickname', ''));
        if ('' === $nickname) {
            $nickname = $this->playerSession->nickname();
        }

        try {
            [$lobby, $host] = $this->lobbyManager->createLobby($gameId, $nickname);
        } catch (GameException $e) {
            $this->addFlash('error', $e->translate($this->translator));

            return $this->redirectToRoute('app_home');
        }

        $this->playerSession->remember($lobby->code, $host);

        return $this->redirectToRoute('app_lobby_show', ['code' => $lobby->code]);
    }

    #[Route('/join', name: 'app_lobby_join', methods: ['POST'])]
    public function join(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('lobby', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $code = strtoupper(trim((string) $request->request->get('code', '')));
        $nickname = trim((string) $request->request->get('nickname', ''));
        if ('' === $nickname) {
            $nickname = $this->playerSession->nickname();
        }

        try {
            $lobby = $this->lobbyManager->getLobby($code);

            if (null === $this->playerSession->playerFor($lobby)) {
                [$lobby, $player] = $this->lobbyManager->joinLobby($code, $nickname);
                $this->playerSession->remember($lobby->code, $player);
                $this->broadcaster->broadcast($lobby);
            }
        } catch (GameException $e) {
            $this->addFlash('error', $e->translate($this->translator));

            return $this->redirectToRoute('app_home');
        }

        return $this->redirectToRoute('app_lobby_show', ['code' => $code]);
    }

    #[Route('/{code}', name: 'app_lobby_show', requirements: ['code' => '[A-Za-z0-9]{6}'], methods: ['GET'])]
    public function show(string $code): Response
    {
        try {
            $lobby = $this->lobbyManager->getLobby($code);
        } catch (LobbyNotFoundException $e) {
            $this->addFlash('error', $e->translate($this->translator));

            return $this->redirectToRoute('app_home');
        }

        $game = $this->games->get($lobby->gameId);
        $me = $this->playerSession->playerFor($lobby);

        return $this->render('lobby/show.html.twig', [
            'lobby' => $lobby,
            'game' => $game,
            'me' => $me,
            'nickname' => $this->playerSession->nickname(),
            'view' => null !== $lobby->state ? $game->buildView($lobby->state, $me?->id) : null,
            'topic' => GameBroadcaster::topicFor($lobby->code),
        ]);
    }

    #[Route('/{code}/rename', name: 'app_lobby_rename', requirements: ['code' => '[A-Za-z0-9]{6}'], methods: ['POST'])]
    public function rename(string $code, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('lobby', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $lobby = $this->lobbyManager->getLobby($code);
            $me = $this->playerSession->playerFor($lobby);
            if (null === $me) {
                return $this->redirectToRoute('app_lobby_show', ['code' => $code]);
            }

            $nickname = trim((string) $request->request->get('nickname', ''));
            if ('' === $nickname || mb_strlen($nickname) > 26) {
                throw new GameException('error.nickname_length');
            }

            $me->nickname = $nickname;
            $this->lobbyManager->save($lobby);
            $this->playerSession->remember($lobby->code, $me);
            $this->broadcaster->broadcast($lobby);
        } catch (GameException $e) {
            $this->addFlash('error', $e->translate($this->translator));
        }

        return $this->redirectToRoute('app_lobby_show', ['code' => $code]);
    }

    #[Route('/{code}/start', name: 'app_lobby_start', requirements: ['code' => '[A-Za-z0-9]{6}'], methods: ['POST'])]
    public function start(string $code, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('lobby', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $lobby = $this->lobbyManager->getLobby($code);
            $me = $this->playerSession->playerFor($lobby);
            if (null === $me) {
                return $this->redirectToRoute('app_home');
            }

            $this->lobbyManager->startGame($lobby, $me->id);
            $this->broadcaster->broadcast($lobby);
        } catch (GameException $e) {
            $this->addFlash('error', $e->translate($this->translator));
        }

        return $this->redirectToRoute('app_lobby_show', ['code' => $code]);
    }
}
