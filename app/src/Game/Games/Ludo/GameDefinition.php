<?php

declare(strict_types=1);

namespace App\Game\Games\Ludo;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameSetting;
use App\Game\Core\Model\GameSettingType;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\Player;
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
        return 'ludo';
    }

    public function getName(): string
    {
        return 'game.ludo.name';
    }

    public function getDescription(): string
    {
        return 'game.ludo.description';
    }

    public function getIcon(): string
    {
        return 'ludo';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 4;
    }

    public function settings(): array
    {
        return [
            new GameSetting(
                key: 'startOneReleased',
                labelKey: 'setting.ludo.start_one_released',
                type: GameSettingType::Bool,
                default: true,
            ),
            new GameSetting(
                key: 'enforceStartClearingWhilePawnInBase',
                labelKey: 'setting.ludo.enforce_start_clearing_while_pawn_in_base',
                type: GameSettingType::Bool,
                default: true,
            ),
            new GameSetting(
                key: 'allowGoalStretchOvertaking',
                labelKey: 'setting.ludo.allow_goal_stretch_overtaking',
                type: GameSettingType::Bool,
                default: false,
            ),
            new GameSetting(
                key: 'threeSixesPenalty',
                labelKey: 'setting.ludo.three_sixes_penalty',
                type: GameSettingType::Bool,
                default: true,
            ),
            new GameSetting(
                key: 'rerollRule',
                labelKey: 'setting.ludo.reroll_rule',
                type: GameSettingType::Enum,
                default: Options::REROLL_NO_LEGAL_MOVE,
                options: [
                    Options::REROLL_NO_LEGAL_MOVE => 'setting.ludo.reroll_rule.no_legal_move',
                    Options::REROLL_NO_OPEN_FIELD => 'setting.ludo.reroll_rule.no_open_field',
                ],
            ),
        ];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;
        $state->data['roll'] = null;
        $state->data['lastRoll'] = null;
        $state->data['rollAttempts'] = 0;
        $state->data['rollSeq'] = 0;
        $state->data['sixStreak'] = 0;
        $state->data['awaitingBanish'] = false;

        $options = Options::fromState($state);
        foreach ($players as $player) {
            $pawns = array_fill(0, GameRules::PAWNS_PER_PLAYER, -1);
            if ($options->startOneReleased) {
                $pawns[0] = 0; // one pawn starts already released, on the start square
            }
            $state->data['pawns'][$player->id] = $pawns;
        }

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        if ($state->data['awaitingBanish']) {
            $this->banish($state, $this->stringParam($payload, 'banish'));

            return;
        }

        match ($payload['action'] ?? '') {
            'roll' => $this->roll($state),
            'move' => $this->move($state, $this->stringParam($payload, 'from')),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/ludo/board.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function roll(GameState $state): void
    {
        if (null !== $state->data['roll']) {
            $this->invalidMove('error.ludo.already_rolled');
        }

        $options = Options::fromState($state);
        $player = $state->currentPlayer();
        $value = random_int(1, 6);
        $state->data['lastRoll'] = $value;
        ++$state->data['rollSeq'];
        $state->logGameEvent('log.ludo.rolled', ['%player%' => $player->nickname, '%value%' => $value]);

        $state->data['sixStreak'] = 6 === $value ? $state->data['sixStreak'] + 1 : 0;

        if ($options->threeSixesPenalty && 3 === $state->data['sixStreak']) {
            $state->data['sixStreak'] = 0;
            $this->startBanish($state, $player);

            return;
        }

        $seats = $this->seats($state);

        // Whether THIS specific roll gives any pawn (base, ring, or goal stretch) a move is
        // always checked the same way, regardless of the reroll rule - if it does, it must be
        // used, full stop.
        if ($this->rules->hasAnyLegalMove($state->data['pawns'], $seats, $player->id, $value, $options)) {
            $state->data['roll'] = $value;
            $state->data['rollAttempts'] = 0;

            return;
        }

        $state->logGameEvent('log.ludo.no_move', ['%player%' => $player->nickname]);

        if (6 === $value) {
            return;
        }

        // How many attempts the player gets is decided purely by board state, independent of
        // what was actually rolled - the reroll rule only controls this ceiling.
        $maxAttempts = Options::REROLL_NO_OPEN_FIELD === $options->rerollRule
            ? ($this->rules->hasAnyOpenFieldPawn($state->data['pawns'][$player->id]) ? 1 : 3)
            : ($this->rules->hasAnyOnBoardTheoreticalMove($state->data['pawns'], $seats, $player->id, $options) ? 1 : 3);

        if (++$state->data['rollAttempts'] < $maxAttempts) {
            return;
        }

        $state->data['rollAttempts'] = 0;
        $state->advanceTurn();
    }

    /**
     * Rolling a third six in a row (when enabled) forfeits the move that six
     * would have granted - the player must send one of their own pawns back
     * to base instead, then their turn ends.
     */
    private function startBanish(GameState $state, Player $player): void
    {
        $state->data['roll'] = null;

        $eligible = array_filter(
            $state->data['pawns'][$player->id],
            static fn (int $progress): bool => $progress > -1 && GameRules::FINISH_PROGRESS !== $progress,
        );

        if ([] === $eligible) {
            $state->logGameEvent('log.ludo.three_sixes_no_pawn', ['%player%' => $player->nickname]);
            $state->advanceTurn();

            return;
        }

        $state->data['awaitingBanish'] = true;
        $state->logGameEvent('log.ludo.three_sixes', ['%player%' => $player->nickname]);
    }

    private function banish(GameState $state, string $key): void
    {
        $player = $state->currentPlayer();
        $seat = $this->seats($state)[$player->id];
        $ownPawns = $state->data['pawns'][$player->id];

        $pawnIndex = $this->pawnIndexAtKey($ownPawns, $seat, $key);
        if (null === $pawnIndex || -1 === $ownPawns[$pawnIndex] || GameRules::FINISH_PROGRESS === $ownPawns[$pawnIndex]) {
            $this->invalidMove('error.ludo.illegal_pawn');
        }

        $state->data['pawns'][$player->id][$pawnIndex] = -1;
        $state->data['awaitingBanish'] = false;
        $state->logGameEvent('log.ludo.banished', ['%player%' => $player->nickname]);
        $state->advanceTurn();
    }

    private function move(GameState $state, string $fromKey): void
    {
        $roll = $state->data['roll'];
        if (null === $roll) {
            $this->invalidMove('error.ludo.roll_first');
        }

        $player = $state->currentPlayer();
        $seats = $this->seats($state);
        $seat = $seats[$player->id];

        $pawnIndex = $this->pawnIndexAtKey($state->data['pawns'][$player->id], $seat, $fromKey);

        $legal = $this->rules->legalMoves($state->data['pawns'], $seats, $player->id, $roll, Options::fromState($state));
        if (null === $pawnIndex || !\in_array($pawnIndex, $legal, true)) {
            $this->invalidMove('error.ludo.illegal_pawn');
        }

        $progress = &$state->data['pawns'][$player->id][$pawnIndex];

        if (-1 === $progress) {
            $progress = 0;
            $this->captureIfOpponent($state, $seats, $this->rules->startIndex($seat), $player);
            $state->logGameEvent('log.ludo.released', ['%player%' => $player->nickname]);
        } else {
            $target = $progress + $roll;
            if ($target <= GameRules::RING_LENGTH - 1) {
                $this->captureIfOpponent($state, $seats, $this->rules->ringIndexFor($seat, $target), $player);
            }
            $progress = $target;
            $state->logGameEvent('log.ludo.moved', ['%player%' => $player->nickname]);
        }
        unset($progress);

        $state->data['roll'] = null;

        if ($this->rules->hasWon($state->data['pawns'][$player->id])) {
            $state->finish($player->id);
            $state->logEvent('log.won', ['%player%' => $player->nickname]);

            return;
        }

        if (6 !== $roll) {
            $state->advanceTurn();
        }
    }

    /**
     * @param list<int> $ownPawns
     */
    private function pawnIndexAtKey(array $ownPawns, int $seat, string $key): ?int
    {
        if (preg_match('/^base:(\d+):(\d+)$/', $key, $m)) {
            $slot = (int) $m[2];

            return (int) $m[1] === $seat && -1 === ($ownPawns[$slot] ?? null) ? $slot : null;
        }

        if (preg_match('/^ring:(\d+)$/', $key, $m)) {
            $ringIndex = (int) $m[1];
            foreach ($ownPawns as $index => $progress) {
                if ($progress >= 0 && $this->rules->ringIndexFor($seat, $progress) === $ringIndex) {
                    return $index;
                }
            }

            return null;
        }

        if (preg_match('/^goal:(\d+):(\d+)$/', $key, $m)) {
            if ((int) $m[1] !== $seat) {
                return null;
            }
            $progress = GameRules::RING_LENGTH + (int) $m[2];
            foreach ($ownPawns as $index => $pawnProgress) {
                if ($pawnProgress === $progress) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $seats
     */
    private function captureIfOpponent(GameState $state, array $seats, int $ringIndex, Player $player): void
    {
        $occupant = $this->rules->pawnAtRingIndex($state->data['pawns'], $seats, $ringIndex);
        if (null === $occupant || $occupant['playerId'] === $player->id) {
            return;
        }

        $state->data['pawns'][$occupant['playerId']][$occupant['pawnIndex']] = -1;
        $opponent = $state->playerById($occupant['playerId']);
        $state->logGameEvent('log.ludo.captured', [
            '%player%' => $player->nickname,
            '%opponent%' => $opponent->nickname,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function seats(GameState $state): array
    {
        $seats = [];
        foreach ($state->players as $p) {
            $seats[$p->id] = $p->seat;
        }

        return $seats;
    }
}
