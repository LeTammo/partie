<?php

declare(strict_types=1);

namespace App\Controller;

use App\Game\Core\Service\GameRegistry;
use App\Game\Core\Service\PlayerSession;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(GameRegistry $games, PlayerSession $playerSession): Response
    {
        return $this->render('home/index.html.twig', [
            'games' => $games->all(),
            'nickname' => $playerSession->lastNickname(),
        ]);
    }
}
