<?php

declare(strict_types=1);

namespace App\Game\Games\Ludo;

use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;

// How to use, see
// docs/components/tokens-and-boards.md
final readonly class GameRenderer
{
    private const array SEAT_COLORS = ['softblue', 'sage', 'terracotta', 'sunny'];

    /**
     * The 40 shared squares, in walk order starting at seat 0's start square.
     * Index N is seat 0's progress-N square; seat S's progress-N square is
     * RING_PATH[(10 * S + N) % 40] - see GameRules::startIndex().
     *
     * @var list<array{0: int, 1: int}>
     */
    private const array RING_PATH = [
        [4, 0], [4, 1], [4, 2], [4, 3], [4, 4], [3, 4], [2, 4], [1, 4], [0, 4], [0, 5],
        [0, 6], [1, 6], [2, 6], [3, 6], [4, 6], [4, 7], [4, 8], [4, 9], [4, 10], [5, 10],
        [6, 10], [6, 9], [6, 8], [6, 7], [6, 6], [7, 6], [8, 6], [9, 6], [10, 6], [10, 5],
        [10, 4], [9, 4], [8, 4], [7, 4], [6, 4], [6, 3], [6, 2], [6, 1], [6, 0], [5, 0],
    ];

    /**
     * Per seat, the 4 private squares leading to the center - step 0 sits
     * right next to the ring, step 3 sits right next to the center.
     *
     * @var list<list<array{0: int, 1: int}>>
     */
    private const array HOME_LANES = [
        [[5, 1], [5, 2], [5, 3], [5, 4]],
        [[1, 5], [2, 5], [3, 5], [4, 5]],
        [[5, 9], [5, 8], [5, 7], [5, 6]],
        [[9, 5], [8, 5], [7, 5], [6, 5]],
    ];

    /**
     * @var list<list<array{0: int, 1: int}>>
     */
    private const array BASE_SLOTS = [
        [[1, 1], [1, 2], [2, 1], [2, 2]],
        [[1, 8], [1, 9], [2, 8], [2, 9]],
        [[8, 8], [8, 9], [9, 8], [9, 9]],
        [[8, 1], [8, 2], [9, 1], [9, 2]],
    ];

    /** @var list<array{0: int, 1: int}> top-left corner of each seat's 4x4 backdrop */
    private const array BACKDROP_ANCHORS = [
        [0, 0], [0, 7], [7, 7], [7, 0],
    ];

    private const int BOARD_SIZE = 11;

    public function __construct(private GameRules $rules)
    {
    }

    /**
     * @return array{
     *     backdrops: list<array{style: string}>,
     *     background: list<array{style: string}>,
     *     cells: list<array{style: string, pawn: ?array{color: string, mine: bool}, clickable: bool, pawnIndex: ?int, target: bool}>,
     *     baseSlots: list<array{style: string, pawn: ?array{color: string, mine: bool}, clickable: bool, pawnIndex: int}>,
     *     myTurn: bool, roll: ?int, canRoll: bool
     * }
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $myTurn = $state->isViewersTurn($viewerId);
        $roll = $state->data['roll'];
        $seats = $this->seats($state);
        $pawns = $state->data['pawns'];
        $seatCount = \count($state->players);

        $legal = (null !== $viewerId && $myTurn && null !== $roll && GameStatus::Running === $state->status)
            ? $this->rules->legalMoves($pawns, $seats, $viewerId, $roll)
            : [];

        $targets = [] !== $legal ? $this->targetKeys($pawns[$viewerId], $seats[$viewerId], $roll, $legal) : [];

        $locations = $this->pawnLocations($pawns, $seats);
        $used = [];

        $backdrops = [];
        for ($seat = 0; $seat < 4; ++$seat) {
            [$row, $col] = self::BACKDROP_ANCHORS[$seat];
            $tier = $seat < $seatCount ? '100' : '50';
            $backdrops[] = ['style' => \sprintf(
                'grid-row:%d / span 4;grid-column:%d / span 4;background-color:var(--color-%s-%s);',
                $row + 1,
                $col + 1,
                self::SEAT_COLORS[$seat],
                $tier,
            )];
        }

        $cells = [];
        for ($i = 0; $i < GameRules::RING_LENGTH; ++$i) {
            [$row, $col] = self::RING_PATH[$i];
            $used["$row,$col"] = true;

            $fill = null;
            if (0 === $i % 10) {
                $startSeat = \intdiv($i, 10);
                $tier = $startSeat < $seatCount ? '500' : '100';
                $fill = \sprintf('var(--color-%s-%s)', self::SEAT_COLORS[$startSeat], $tier);
            }

            $cells[] = $this->buildCell($row, $col, $locations['ring:'.$i] ?? null, $viewerId, $legal, $fill, isset($targets['ring:'.$i]));
        }
        for ($seat = 0; $seat < 4; ++$seat) {
            $tier = $seat < $seatCount ? '300' : '100';
            $fill = \sprintf('var(--color-%s-%s)', self::SEAT_COLORS[$seat], $tier);

            foreach (self::HOME_LANES[$seat] as $step => [$row, $col]) {
                $used["$row,$col"] = true;
                $cells[] = $this->buildCell($row, $col, $locations["home:$seat:$step"] ?? null, $viewerId, $legal, $fill, isset($targets["home:$seat:$step"]));
            }
        }

        $baseSlots = [];
        for ($seat = 0; $seat < 4; ++$seat) {
            foreach (self::BASE_SLOTS[$seat] as $slot => [$row, $col]) {
                $used["$row,$col"] = true;
                $baseSlots[] = $this->buildCell($row, $col, $locations["base:$seat:$slot"] ?? null, $viewerId, $legal);
            }
        }

        $background = [];
        for ($row = 0; $row < self::BOARD_SIZE; ++$row) {
            for ($col = 0; $col < self::BOARD_SIZE; ++$col) {
                if (!isset($used["$row,$col"])) {
                    $background[] = ['style' => \sprintf('grid-row:%d;grid-column:%d;', $row + 1, $col + 1)];
                }
            }
        }

        return [
            'backdrops' => $backdrops,
            'background' => $background,
            'cells' => $cells,
            'baseSlots' => $baseSlots,
            'myTurn' => $myTurn,
            'roll' => $state->data['lastRoll'],
            'canRoll' => $myTurn && null === $roll && GameStatus::Running === $state->status,
        ];
    }

    /**
     * @param array{playerId: string, pawnIndex: int, seat: int}|null $occupant
     * @param list<int>                                               $legal
     */
    private function buildCell(int $row, int $col, ?array $occupant, ?string $viewerId, array $legal, ?string $fill = null, bool $target = false): array
    {
        $pawn = null;
        $clickable = false;
        $pawnIndex = null;

        if (null !== $occupant) {
            $mine = $occupant['playerId'] === $viewerId;
            $pawn = ['color' => self::SEAT_COLORS[$occupant['seat']], 'mine' => $mine];
            $pawnIndex = $occupant['pawnIndex'];
            $clickable = $mine && \in_array($occupant['pawnIndex'], $legal, true);
        }

        $style = \sprintf('grid-row:%d;grid-column:%d;', $row + 1, $col + 1);
        if (null !== $fill) {
            $style .= "background-color:$fill;";
        }

        return [
            'style' => $style,
            'pawn' => $pawn,
            'clickable' => $clickable,
            'pawnIndex' => $pawnIndex,
            'target' => $target,
        ];
    }

    /**
     * @param list<int> $ownPawns
     * @param list<int> $legal
     *
     * @return array<string, true>
     */
    private function targetKeys(array $ownPawns, int $seat, int $roll, array $legal): array
    {
        $targets = [];
        foreach ($legal as $pawnIndex) {
            $progress = $ownPawns[$pawnIndex];
            $targetProgress = -1 === $progress ? 0 : $progress + $roll;

            $key = $targetProgress <= GameRules::RING_LENGTH - 1
                ? 'ring:'.$this->rules->ringIndexFor($seat, $targetProgress)
                : 'home:'.$seat.':'.($targetProgress - GameRules::RING_LENGTH);

            $targets[$key] = true;
        }

        return $targets;
    }

    /**
     * @return array<string, int>
     */
    private function seats(GameState $state): array
    {
        $seats = [];
        foreach ($state->players as $player) {
            $seats[$player->id] = $player->seat;
        }

        return $seats;
    }

    /**
     * @param array<string, list<int>> $pawns
     * @param array<string, int>       $seats
     *
     * @return array<string, array{playerId: string, pawnIndex: int, seat: int}>
     */
    private function pawnLocations(array $pawns, array $seats): array
    {
        $map = [];
        foreach ($pawns as $playerId => $progresses) {
            $seat = $seats[$playerId];
            foreach ($progresses as $pawnIndex => $progress) {
                $key = match (true) {
                    -1 === $progress => "base:$seat:$pawnIndex",
                    $progress <= GameRules::RING_LENGTH - 1 => 'ring:'.$this->rules->ringIndexFor($seat, $progress),
                    default => "home:$seat:".($progress - GameRules::RING_LENGTH),
                };
                $map[$key] = ['playerId' => $playerId, 'pawnIndex' => $pawnIndex, 'seat' => $seat];
            }
        }

        return $map;
    }
}
