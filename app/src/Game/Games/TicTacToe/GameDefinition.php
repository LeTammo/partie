<?php

declare(strict_types=1);

namespace App\Game\Games\TicTacToe;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\Board;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\Token;
use App\Game\Core\Model\TokenShape;
use App\Game\Core\Service\AbstractGameDefinition;

final readonly class GameDefinition extends AbstractGameDefinition
{
    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'tictactoe';
    }

    public function getName(): string
    {
        return 'game.tictactoe.name';
    }

    public function getDescription(): string
    {
        return 'game.tictactoe.description';
    }

    public function getIcon(): string
    {
        return 'tictactoe';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 2;
    }

    public function createInitialState(array $players): GameState
    {
        $state = new GameState($this->getId(), $players, new Board(3, 3));
        $state->data['variants'] = [
            $players[0]->id => 'x',
            $players[1]->id => 'o',
        ];

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        $x = (int) ($payload['x'] ?? -1);
        $y = (int) ($payload['y'] ?? -1);

        if (!$state->board->isEmpty($x, $y)) {
            $this->invalidMove('error.cell_taken');
        }

        $player = $state->currentPlayer();
        $variant = $state->data['variants'][$playerId];

        $state->board->place($x, $y, new Token(
            ownerId: $playerId,
            shape: TokenShape::Custom,
            outerColor: $player->color,
            variant: $variant,
        ));

        $state->logGameEvent('log.tictactoe.placed', [
            '%player%' => $player->nickname,
            '%symbol%' => strtoupper($variant),
            '%x%' => $x + 1,
            '%y%' => $y + 1,
        ]);

        if (null !== ($winnerId = $this->rules->findWinner($state->board))) {
            $state->finish($winnerId);
            $state->logEvent('log.won', ['%player%' => $player->nickname]);
        } elseif ($this->rules->isBoardFull($state->board)) {
            $state->finish(null);
            $state->logEvent('log.draw_full');
        } else {
            $state->advanceTurn();
        }
    }

    public function getTemplate(): string
    {
        return 'game/tictactoe/board.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }
}
