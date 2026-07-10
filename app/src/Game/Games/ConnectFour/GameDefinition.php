<?php

declare(strict_types=1);

namespace App\Game\Games\ConnectFour;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\Board;
use App\Game\Core\Model\GameSetting;
use App\Game\Core\Model\GameSettingType;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\Token;
use App\Game\Core\Model\TokenShape;
use App\Game\Core\Service\AbstractGameDefinition;

final readonly class GameDefinition extends AbstractGameDefinition
{
    private const array TOKEN_COLORS = [
        ['#e8a598', '#f3cec6'], // soft terracotta
        ['#e9d8a6', '#f6ecd0'], // muted gold
    ];

    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'connectfour';
    }

    public function getName(): string
    {
        return 'game.connectfour.name';
    }

    public function getDescription(): string
    {
        return 'game.connectfour.description';
    }

    public function getIcon(): string
    {
        return 'connectfour';
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
                key: 'boardWidth',
                labelKey: 'setting.connectfour.board_width',
                type: GameSettingType::Int,
                default: 7,
                min: 4,
                max: 10,
            ),
            new GameSetting(
                key: 'boardHeight',
                labelKey: 'setting.connectfour.board_height',
                type: GameSettingType::Int,
                default: 6,
                min: 4,
                max: 10,
            ),
            new GameSetting(
                key: 'connectCount',
                labelKey: 'setting.connectfour.connect_count',
                type: GameSettingType::Int,
                default: 4,
                min: 3,
                max: 6,
            ),
        ];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players, null);
        $state->data['settings'] = $settings;

        $state->board = new Board(
            (int) ($this->setting($state, 'boardWidth') ?? 7),
            (int) ($this->setting($state, 'boardHeight') ?? 6)
        );

        foreach ($players as $i => $player) {
            $state->data['colors'][$player->id] = self::TOKEN_COLORS[$i % 2];
        }

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        $column = $this->intParam($payload, 'column');
        $y = $this->rules->dropRow($state->board, $column);
        if (null === $y) {
            $this->invalidMove('error.column_full');
        }

        $player = $state->currentPlayer();
        [$outer, $inner] = $state->data['colors'][$playerId];

        $state->board->place($column, $y, new Token(
            ownerId: $playerId,
            shape: TokenShape::Round,
            outerColor: $outer,
            innerColor: $inner,
        ));

        $state->logGameEvent('log.connectfour.dropped', ['%player%' => $player->nickname, '%column%' => $column + 1]);

        $connectCount = (int) ($this->setting($state, 'connectCount') ?? 4);
        if ($this->rules->isWinningMove($state->board, $column, $y, $connectCount)) {
            $state->finish($playerId);
            $state->logGameEvent('log.connectfour.won', ['%player%' => $player->nickname]);
        } elseif ($this->rules->isBoardFull($state->board)) {
            $state->finish(null);
            $state->logEvent('log.draw_full');
        } else {
            $state->advanceTurn();
        }
    }

    public function getTemplate(): string
    {
        return 'game/connectfour/board.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }
}
