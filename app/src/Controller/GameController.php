<?php

declare(strict_types=1);

namespace App\Controller;

use App\Game\Core\Exception\GameException;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Service\AutoPlayingEngineInterface;
use App\Game\Core\Service\GameBroadcaster;
use App\Game\Core\Service\GameRegistry;
use App\Game\Core\Service\LobbyManager;
use App\Game\Core\Service\PlayerSession;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GameController extends AbstractController
{
    public function __construct(
        private readonly LobbyManager $lobbyManager,
        private readonly GameRegistry $games,
        private readonly PlayerSession $playerSession,
        private readonly GameBroadcaster $broadcaster,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/lobby/{code}/move', name: 'app_game_move', requirements: ['code' => '[A-Za-z0-9]{6}'], methods: ['POST'])]
    public function move(string $code, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('game', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $lobby = $this->lobbyManager->getLobby($code);
            $me = $this->playerSession->playerFor($lobby);

            if (null === $me) {
                $this->addFlash('error', $this->translator->trans('error.spectator'));

                return $this->redirectToRoute('app_lobby_show', ['code' => $code]);
            }
            if (GameStatus::Running !== $lobby->status || null === $lobby->state) {
                throw new InvalidMoveException('error.not_running');
            }

            $game = $this->games->get($lobby->gameId);
            $payload = $request->request->all();
            unset($payload['_token']);

            $game->applyMove($lobby->state, $me->id, $payload);

            if (GameStatus::Finished === $lobby->state->status) {
                $lobby->status = GameStatus::Finished;
            }

            $this->lobbyManager->save($lobby);
            $this->broadcaster->broadcast($lobby);
        } catch (GameException $e) {
            $this->addFlash('error', $e->translate($this->translator));
        }

        return $this->redirectToRoute('app_lobby_show', ['code' => $code]);
    }

    #[Route('/lobby/{code}/tick', name: 'app_game_tick', requirements: ['code' => '[A-Za-z0-9]{6}'], methods: ['POST'])]
    public function tick(string $code, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('game', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $lobby = $this->lobbyManager->getLobby($code);
        } catch (GameException) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        $game = $this->games->get($lobby->gameId);
        $state = $lobby->state;

        if (!$game instanceof AutoPlayingEngineInterface
            || null === $state
            || !$game->hasAutoStep($state)
            || ($state->data['autoStep'] ?? 0) !== $request->request->getInt('step', -1)) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        $game->applyAutoStep($state);
        if (GameStatus::Finished === $state->status) {
            $lobby->status = GameStatus::Finished;
        }

        $this->lobbyManager->save($lobby);
        $this->broadcaster->broadcast($lobby);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
