<?php

declare(strict_types=1);

namespace App\Game\Games\TicTacToe;

use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Token;
use App\Game\Core\View\BoardViews;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class GameRenderer
{
    private const string CELL_CLASS = 'grid size-20 place-items-center rounded-2xl bg-white shadow-soft sm:size-24';
    private const string MARK_CLASS = 'anim-pop pointer-events-none text-4xl sm:text-5xl';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $myTurn = $state->isViewersTurn($viewerId);
        $myVariant = null !== $viewerId ? ($state->data['variants'][$viewerId] ?? null) : null;
        $mySymbol = null !== $myVariant ? ('x' === $myVariant ? '✕' : '◯') : null;
        $myColor = null !== $viewerId ? $state->playerById($viewerId)?->color : null;

        $cells = [];
        foreach (BoardViews::grid($state->board, static fn (int $x, int $y, ?Token $token): array => [
            'x' => $x,
            'y' => $y,
            'symbol' => $token?->symbol,
            'color' => $token?->outerColor,
            'playable' => $myTurn && null === $token && GameStatus::Running === $state->status,
        ]) as $row) {
            foreach ($row as $cell) {
                if ($cell['playable']) {
                    $cells[] = [
                        'form' => [
                            'fields' => ['x' => $cell['x'], 'y' => $cell['y']],
                            'attr' => ['id' => 'ttt-cell-'.$cell['x'].'-'.$cell['y']],
                            'buttonClass' => self::CELL_CLASS.' transition hover:bg-softblue-50 hover:shadow-lifted',
                            'buttonAria' => $this->translator->trans(
                                'tictactoe.place',
                                ['%x%' => $cell['x'] + 1, '%y%' => $cell['y'] + 1],
                                'tictactoe',
                            ),
                            'template' => [
                                'shape' => 'plain',
                                'symbol' => $mySymbol,
                                'symbolColor' => $myColor,
                                'size' => '',
                                'class' => self::MARK_CLASS,
                            ],
                        ],
                    ];
                    continue;
                }

                $cells[] = [
                    'attr' => ['id' => 'ttt-cell-'.$cell['x'].'-'.$cell['y']],
                    'class' => self::CELL_CLASS,
                    'token' => null !== $cell['symbol'] ? [
                        'shape' => 'plain',
                        'symbol' => $cell['symbol'],
                        'symbolColor' => $cell['color'],
                        'size' => '',
                        'key' => 'ttt-mark-'.$cell['x'].'-'.$cell['y'],
                        'class' => self::MARK_CLASS,
                    ] : null,
                ];
            }
        }

        return [
            'board' => [
                'cols' => $state->board->width,
                'rows' => $state->board->height,
                'class' => 'grid gap-2 rounded-3xl bg-cream p-3',
                'cells' => $cells,
            ],
        ];
    }
}
