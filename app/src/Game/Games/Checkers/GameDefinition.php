<?php

declare(strict_types=1);

namespace App\Game\Games\Checkers;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\Board;
use App\Game\Core\Model\GameSetting;
use App\Game\Core\Model\GameSettingType;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Player;
use App\Game\Core\Model\Token;
use App\Game\Core\Model\TokenShape;
use App\Game\Core\Service\AbstractGameDefinition;
use App\Game\Core\View\MoveMap;

final readonly class GameDefinition extends AbstractGameDefinition
{
    private const array TOKEN_COLORS = [
        ['#a3b8a3', '#c9d8c9'], // sage green (moves down)
        ['#b0a7c7', '#d4cde4'], // soft lavender (moves up)
    ];

    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
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
        return 'games/checkers';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 2;
    }

    public function settings(): array
    {
        return [
            new GameSetting(
                key: 'forcedCapture',
                labelKey: 'setting.checkers.forced_capture',
                type: GameSettingType::Bool,
                default: false,
            ),
        ];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $board = new Board(8, 8);
        $state = new GameState($this->getId(), $players, $board);
        $state->data['settings'] = $settings;

        $state->data['directions'] = [
            $players[0]->id => 1,
            $players[1]->id => -1,
        ];
        $state->data['mustContinueFrom'] = null;
        $state->data['pendingSacrifice'] = null;

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
                            centerColor: $inner,
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

        if (null !== $state->data['pendingSacrifice']) {
            $this->resolveSacrifice($state, $playerId, $payload);

            return;
        }

        $from = MoveMap::coordsOf($this->stringParam($payload, 'from'));
        $to = MoveMap::coordsOf($this->stringParam($payload, 'to'));
        if (null === $from || null === $to) {
            throw new InvalidMoveException('error.move_not_allowed');
        }
        [$fromX, $fromY] = $from;
        [$toX, $toY] = $to;

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
        $captured = null !== $move['captureX'];

        $missedOrigins = [];
        if (!$captured && !$capturesOnly && true === $this->setting($state, 'forcedCapture')) {
            $missedOrigins = $this->missedCaptureOrigins($board, $playerId, $direction);
        }

        $board->move($fromX, $fromY, $toX, $toY);
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

        if ([] !== $missedOrigins) {
            // the moved piece itself may have been one of the origins that missed a capture - it now lives at (toX, toY)
            $candidates = array_map(
                static fn (array $origin): array => [$fromX, $fromY] === $origin ? [$toX, $toY] : $origin,
                $missedOrigins,
            );

            if (1 === \count($candidates)) {
                $this->huffPiece($state, $board, $candidates[0], $playerId, $player);
                if (GameStatus::Finished === $state->status) {
                    return;
                }
            } else {
                $state->data['mustContinueFrom'] = null;
                $state->data['pendingSacrifice'] = $candidates;

                return;
            }
        }

        if ($captured && !$promoted && $this->rules->canCaptureFrom($board, $toX, $toY, $direction)) {
            $state->data['mustContinueFrom'] = [$toX, $toY];

            return;
        }
        $state->data['mustContinueFrom'] = null;

        $this->finishOrAdvance($state, $board, $playerId, $player);
    }

    private function resolveSacrifice(GameState $state, string $playerId, array $payload): void
    {
        $candidates = $state->data['pendingSacrifice'];
        $raw = $this->stringParam($payload, 'sacrifice');

        $chosen = array_find($candidates, static fn (array $c): bool => $raw === MoveMap::cellKey($c[0], $c[1]));
        if (null === $chosen) {
            $this->invalidMove('error.checkers.choose_sacrifice');
        }

        $player = $state->currentPlayer();
        $state->data['pendingSacrifice'] = null;

        $board = $state->board;
        $this->huffPiece($state, $board, $chosen, $playerId, $player);
        if (GameStatus::Finished === $state->status) {
            return;
        }

        $this->finishOrAdvance($state, $board, $playerId, $player);
    }

    /**
     * @param array{0: int, 1: int} $square
     */
    private function huffPiece(GameState $state, Board $board, array $square, string $playerId, Player $player): void
    {
        [$hx, $hy] = $square;
        $board->remove($hx, $hy);
        $state->logGameEvent('log.checkers.huffed', ['%player%' => $player->nickname]);

        if (0 === $board->countTokensOf($playerId)) {
            $opponent = $state->players[($state->currentTurnIndex + 1) % 2];
            $state->finish($opponent->id);
            $state->logEvent('log.won', ['%player%' => $opponent->nickname]);
        }
    }

    private function finishOrAdvance(GameState $state, Board $board, string $playerId, Player $player): void
    {
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

    /**
     * @return list<array{0: int, 1: int}> board coordinates of pieces that had a capture available and didn't take it
     */
    private function missedCaptureOrigins(Board $board, string $playerId, int $direction): array
    {
        $origins = [];
        foreach ($this->rules->allMovesFor($board, $playerId, $direction) as $key => $moves) {
            foreach ($moves as $move) {
                if (null !== $move['captureX']) {
                    $origins[] = array_map('intval', explode(':', $key));
                    break;
                }
            }
        }

        return $origins;
    }
}
