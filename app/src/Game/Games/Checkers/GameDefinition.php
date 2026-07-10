<?php

declare(strict_types=1);

namespace App\Game\Games\Checkers;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\Board;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\Token;
use App\Game\Core\Model\TokenShape;
use App\Game\Core\Service\AbstractGameDefinition;

final readonly class GameDefinition extends AbstractGameDefinition
{
    private const array TOKEN_COLORS = [
        ['#a3b8a3', '#c9d8c9'], // sage green (moves down)
        ['#b0a7c7', '#d4cde4'], // soft lavender (moves up)
    ];

    public function __construct(
        private readonly GameRules $rules,
        private readonly GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'checkers';
    }

    public function getName(): string
    {
        return 'game.checkers.name';
    }

    public function getDescription(): string
    {
        return 'game.checkers.description';
    }

    public function getIcon(): string
    {
        return 'checkers';
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
        $board = new Board(8, 8);
        $state = new GameState($this->getId(), $players, $board);

        $state->data['directions'] = [
            $players[0]->id => 1,
            $players[1]->id => -1,
        ];
        $state->data['mustContinueFrom'] = null;

        foreach ([0, 1] as $seat) {
            [$outer, $inner] = self::TOKEN_COLORS[$seat];
            $rows = 0 === $seat ? [0, 1, 2] : [5, 6, 7];
            foreach ($rows as $y) {
                for ($x = 0; $x < 8; ++$x) {
                    if ($this->rules->isDarkSquare($x, $y)) {
                        $board->place($x, $y, new Token(
                            ownerId: $players[$seat]->id,
                            shape: TokenShape::Round,
                            outerColor: $outer,
                            innerColor: $inner,
                        ));
                    }
                }
            }
        }

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        $fromX = (int) ($payload['fromX'] ?? -1);
        $fromY = (int) ($payload['fromY'] ?? -1);
        $toX = (int) ($payload['toX'] ?? -1);
        $toY = (int) ($payload['toY'] ?? -1);

        $board = $state->board;
        $token = $board->get($fromX, $fromY);
        if (null === $token || $token->ownerId !== $playerId) {
            $this->invalidMove('error.checkers.no_piece');
        }

        $direction = $state->data['directions'][$playerId];

        $continueFrom = $state->data['mustContinueFrom'];
        $capturesOnly = null !== $continueFrom;
        if ($capturesOnly && [$fromX, $fromY] !== $continueFrom) {
            $this->invalidMove('error.checkers.must_continue');
        }

        $move = $this->findMove($board, $fromX, $fromY, $toX, $toY, $direction, $capturesOnly);
        if (null === $move) {
            throw new InvalidMoveException('error.move_not_allowed');
        }

        $player = $state->currentPlayer();
        $board->move($fromX, $fromY, $toX, $toY);

        $captured = null !== $move['captureX'];
        if ($captured) {
            $board->remove($move['captureX'], $move['captureY']);
            $state->logGameEvent('log.checkers.captured', ['%player%' => $player->nickname]);
        } else {
            $state->logGameEvent('log.checkers.moved', ['%player%' => $player->nickname]);
        }

        $promoted = $this->rules->shouldPromote($token, $toY, $direction, $board->height);
        if ($promoted) {
            $token->promote(GameRules::KING);
            $state->logGameEvent('log.checkers.king', ['%player%' => $player->nickname]);
        }

        if ($captured && !$promoted && $this->rules->canCaptureFrom($board, $toX, $toY, $direction)) {
            $state->data['mustContinueFrom'] = [$toX, $toY];

            return;
        }
        $state->data['mustContinueFrom'] = null;

        $opponent = $state->players[($state->currentTurnIndex + 1) % 2];
        $opponentDirection = $state->data['directions'][$opponent->id];

        if (0 === $board->countTokensOf($opponent->id) || !$this->rules->hasAnyMove($board, $opponent->id, $opponentDirection)) {
            $state->finish($playerId);
            $state->logEvent('log.won', ['%player%' => $player->nickname]);

            return;
        }

        $state->advanceTurn();
    }

    public function getTemplate(): string
    {
        return 'game/checkers/board.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    /**
     * @return array{toX: int, toY: int, captureX: ?int, captureY: ?int}|null
     */
    private function findMove(Board $board, int $fromX, int $fromY, int $toX, int $toY, int $direction, bool $capturesOnly): ?array
    {
        return array_find(
            $this->rules->movesForPiece($board, $fromX, $fromY, $direction, $capturesOnly),
            fn($move) => $move['toX'] === $toX && $move['toY'] === $toY
        );
    }
}
