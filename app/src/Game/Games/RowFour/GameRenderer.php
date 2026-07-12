<?php

declare(strict_types=1);

namespace App\Game\Games\RowFour;

use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Token;
use App\Game\Core\Rules\Gravity;
use App\Game\Core\View\BoardViews;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class GameRenderer
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $myTurn = $state->isViewersTurn($viewerId);
        $board = $state->board;

        $cells = [];
        foreach (BoardViews::grid($board, static fn (int $x, int $y, ?Token $token): array => [
            'x' => $x,
            'outer' => $token?->outerColor,
            'inner' => $token?->centerColor,
        ]) as $row) {
            foreach ($row as $cell) {
                $cells[] = [
                    'attr' => ['data-col' => $cell['x']],
                    'class' => 'grid size-10 place-items-center rounded-full bg-white/70 sm:size-12',
                    'token' => null !== $cell['outer'] ? self::disc($cell['outer'], $cell['inner']) : null,
                ];
            }
        }

        $columns = [];
        $myColors = null !== $viewerId ? ($state->data['colors'][$viewerId] ?? null) : null;
        for ($x = 0; $x < $board->width; ++$x) {
            $columns[] = [
                'column' => $x,
                'playable' => $myTurn
                    && GameStatus::Running === $state->status
                    && null !== Gravity::dropRow($board, $x),
                'aria' => $this->translator->trans('rowfour.drop', ['%column%' => $x + 1], 'rowfour'),
            ];
        }

        return [
            'board' => [
                'cols' => $board->width,
                'rows' => $board->height,
                'class' => 'grid gap-1.5 sm:gap-2',
                'panelClass' => 'rounded-3xl bg-softblue-100 p-3 shadow-soft',
                'cells' => $cells,
                'drop' => null !== $myColors ? [
                    'columns' => $columns,
                    'template' => self::disc($myColors[0], $myColors[1]),
                ] : ['columns' => $columns, 'template' => self::disc('#ffffff', '#ffffff')],
            ],
        ];
    }

    /**
     * @return array<string, mixed> token component params for a disc
     */
    private static function disc(string $outer, ?string $inner): array
    {
        return [
            'outer' => $outer,
            'center' => $inner,
            'centerSize' => 45,
            'size' => 'size-9 sm:size-11',
            'class' => 'anim-drop',
        ];
    }
}
