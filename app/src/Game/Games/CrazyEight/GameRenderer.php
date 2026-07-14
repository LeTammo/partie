<?php

declare(strict_types=1);

namespace App\Game\Games\CrazyEight;

use App\Game\Core\Card\CustomCard;
use App\Game\Core\Card\CustomCardPresenter;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\Player;
use App\Game\Core\View\PlayerViews;

final readonly class GameRenderer
{
    private const array COLORS = ['red', 'yellow', 'green', 'blue'];

    public function __construct(private GameRules $rules)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $table = $state->table;
        $myTurn = $state->isViewersTurn($viewerId);
        /** @var CustomCard $top */
        $top = $table->zone('discard')->top();
        $pendingDraw = $state->data['pendingDraw'];
        $wishedColor = $state->data['wishedColor'];
        $options = Options::fromState($state);

        $players = PlayerViews::build($state, static fn (Player $player): array => [
            'cardCount' => $table->hand($player->id)->count(),
        ]);

        $hand = [];
        if (null !== $viewerId && $table->has('hand:'.$viewerId)) {
            foreach ($table->hand($viewerId)->items as $index => $card) {
                /** @var CustomCard $card */
                $hand[] = CustomCardPresenter::view($card) + [
                    'index' => $index,
                    'playable' => $myTurn && $this->rules->playable(
                        $card,
                        $top,
                        $wishedColor,
                        $pendingDraw,
                        $state->data['pendingDrawValue'],
                        $options->stackDraw2,
                    ),
                    'isWild' => $this->rules->isWild($card),
                ];
            }
        }

        $colors = [];
        foreach (self::COLORS as $color) {
            $colors[] = ['value' => $color, 'labelKey' => 'crazyeight.color.'.$color];
        }

        return [
            'myTurn' => $myTurn,
            'players' => $players,
            'top' => CustomCardPresenter::view($top),
            'wishedColor' => $wishedColor,
            'pendingDraw' => $pendingDraw,
            'drawCount' => $table->zone('stock')->count(),
            'hand' => $hand,
            'hasDrawn' => $state->data['hasDrawn'],
            'canPass' => $myTurn && $state->data['hasDrawn'],
            'canDraw' => $myTurn && ($pendingDraw > 0 || !$state->data['hasDrawn']),
            'colors' => $colors,
        ];
    }
}
