<?php

declare(strict_types=1);

namespace App\Game\Games\Ludo;

use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\View\MoveMap;
use App\Game\Core\Zone\Path;

// How to use, see
// docs/components/tokens-and-boards.md
final readonly class GameRenderer
{
    private const array SEAT_COLORS = ['softblue', 'sage', 'terracotta', 'sunny'];

    /**
     * The 40 shared squares as [row, col], in walk order starting at seat
     * 0's start square. Seat rotation is handled by Path::seatStride.
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
     * Per seat, the 4 private squares [row, col] leading to the center.
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
     * @return array<string, mixed>
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $myTurn = $state->isViewersTurn($viewerId);
        $roll = $state->data['roll'];
        $seats = $this->seats($state);
        $pawns = $state->data['pawns'];
        $seatCount = \count($state->players);

        $ring = new Path('ring', self::xy(self::RING_PATH), seatStride: 10);
        $homes = array_map(static fn (array $lane): array => self::xy($lane), self::HOME_LANES);
        $bases = array_map(static fn (array $slots): array => self::xy($slots), self::BASE_SLOTS);

        $legal = (null !== $viewerId && $myTurn && null !== $roll && GameStatus::Running === $state->status)
            ? $this->rules->legalMoves($pawns, $seats, $viewerId, $roll)
            : [];

        $moves = new MoveMap();
        if ([] !== $legal) {
            $seat = $seats[$viewerId];
            foreach ($legal as $pawnIndex) {
                $moves->add(
                    $this->keyFor($seat, $pawnIndex, $pawns[$viewerId][$pawnIndex]),
                    $this->targetKeyFor($seat, $pawns[$viewerId][$pawnIndex], $roll),
                );
            }
        }

        $locations = $this->pawnLocations($pawns, $seats);
        $used = [];

        $layers = [];
        for ($seat = 0; $seat < 4; ++$seat) {
            [$row, $col] = self::BACKDROP_ANCHORS[$seat];
            $tier = $seat < $seatCount ? '100' : '50';
            $layers[] = [
                'style' => \sprintf(
                    'grid-row:%d / span 4;grid-column:%d / span 4;background-color:var(--color-%s-%s);',
                    $row + 1,
                    $col + 1,
                    self::SEAT_COLORS[$seat],
                    $tier,
                ),
                'class' => 'rounded-2xl',
            ];
        }

        $cells = [];
        for ($i = 0; $i < GameRules::RING_LENGTH; ++$i) {
            [$x, $y] = $ring->at($i);
            $used["$x,$y"] = true;

            $fill = null;
            if (0 === $i % 10) {
                $startSeat = \intdiv($i, 10);
                $tier = $startSeat < $seatCount ? '500' : '100';
                $fill = \sprintf('var(--color-%s-%s)', self::SEAT_COLORS[$startSeat], $tier);
            }

            $cells[] = $this->cell("ring:$i", $x, $y, $locations["ring:$i"] ?? null, $viewerId, $moves, $fill, track: true);
        }
        for ($seat = 0; $seat < 4; ++$seat) {
            $tier = $seat < $seatCount ? '300' : '100';
            $fill = \sprintf('var(--color-%s-%s)', self::SEAT_COLORS[$seat], $tier);

            foreach ($homes[$seat] as $step => [$x, $y]) {
                $used["$x,$y"] = true;
                $cells[] = $this->cell("home:$seat:$step", $x, $y, $locations["home:$seat:$step"] ?? null, $viewerId, $moves, $fill, track: true);
            }
        }
        for ($seat = 0; $seat < 4; ++$seat) {
            foreach ($bases[$seat] as $slot => [$x, $y]) {
                $used["$x,$y"] = true;
                $cells[] = $this->cell("base:$seat:$slot", $x, $y, $locations["base:$seat:$slot"] ?? null, $viewerId, $moves, null, track: false);
            }
        }
        for ($y = 0; $y < self::BOARD_SIZE; ++$y) {
            for ($x = 0; $x < self::BOARD_SIZE; ++$x) {
                if (!isset($used["$x,$y"])) {
                    $cells[] = [
                        'style' => \sprintf('grid-row:%d;grid-column:%d;', $y + 1, $x + 1),
                        'class' => 'grid place-items-center',
                        'dot' => true,
                    ];
                }
            }
        }

        return [
            'board' => [
                'cols' => self::BOARD_SIZE,
                'rows' => self::BOARD_SIZE,
                'class' => 'relative grid gap-1 rounded-3xl bg-cream p-3 shadow-soft',
                'style' => 'width: min(92vw, 34rem); aspect-ratio: 1;',
                'layers' => $layers,
                'cells' => $cells,
            ],
            'moves' => $moves->toArray(),
            'myTurn' => $myTurn,
            'roll' => $state->data['lastRoll'],
            'rollSeq' => $state->data['rollSeq'] ?? 0,
            'canRoll' => $myTurn && null === $roll && GameStatus::Running === $state->status,
        ];
    }

    /**
     * @param array{playerId: string, pawnIndex: int, seat: int}|null $occupant
     *
     * @return array<string, mixed>
     */
    private function cell(string $key, int $x, int $y, ?array $occupant, ?string $viewerId, MoveMap $moves, ?string $fill, bool $track): array
    {
        $style = \sprintf('grid-row:%d;grid-column:%d;', $y + 1, $x + 1);
        if (null !== $fill) {
            $style .= "background-color:$fill;";
        }

        $token = null;
        if (null !== $occupant) {
            $color = self::SEAT_COLORS[$occupant['seat']];
            $movable = $occupant['playerId'] === $viewerId && $moves->has($key);

            $token = [
                'outer' => "var(--color-$color-500)",
                'middle' => "var(--color-$color-700)",
                'center' => "var(--color-$color-300)",
                'middleSize' => 84,
                'centerSize' => 60,
                'size' => 'size-6 sm:size-8',
                'ring' => $movable,
                'class' => $movable ? 'cursor-grab transition hover:scale-110' : 'shadow-strong',
                'attr' => $movable ? [
                    'data-source' => $key,
                    'draggable' => 'true',
                    'data-action' => 'dragstart->dragdrop--piece-move#dragStart dragend->dragdrop--piece-move#dragEnd',
                ] : [],
            ];
        }

        return [
            'key' => $key,
            'style' => $style,
            'class' => 'grid place-items-center'.($track ? ' rounded-full bg-white shadow-soft' : ''),
            'token' => $token,
        ];
    }

    private function keyFor(int $seat, int $pawnIndex, int $progress): string
    {
        return match (true) {
            -1 === $progress => "base:$seat:$pawnIndex",
            $progress <= GameRules::RING_LENGTH - 1 => 'ring:'.$this->rules->ringIndexFor($seat, $progress),
            default => "home:$seat:".($progress - GameRules::RING_LENGTH),
        };
    }

    private function targetKeyFor(int $seat, int $progress, int $roll): string
    {
        $targetProgress = -1 === $progress ? 0 : $progress + $roll;

        return $targetProgress <= GameRules::RING_LENGTH - 1
            ? 'ring:'.$this->rules->ringIndexFor($seat, $targetProgress)
            : "home:$seat:".($targetProgress - GameRules::RING_LENGTH);
    }

    /**
     * @param list<array{0: int, 1: int}> $rowCols [row, col] pairs
     *
     * @return list<array{0: int, 1: int}> [x, y] pairs
     */
    private static function xy(array $rowCols): array
    {
        return array_map(static fn (array $cell): array => [$cell[1], $cell[0]], $rowCols);
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
                $map[$this->keyFor($seat, $pawnIndex, $progress)] = [
                    'playerId' => $playerId,
                    'pawnIndex' => $pawnIndex,
                    'seat' => $seat,
                ];
            }
        }

        return $map;
    }
}
