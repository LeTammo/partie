<?php

declare(strict_types=1);

namespace App\Game\Games\ElevenOut;

use App\Game\Core\Card\CustomCard;
use App\Game\Core\Card\CustomCardPresenter;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\Player;
use App\Game\Core\View\PlayerViews;

final readonly class GameRenderer
{
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
        $board = $state->data['board'];
        $cardsPlayed = $state->data['cardsPlayedThisTurn'] ?? 0;
        $drawCount = $state->data['drawCountThisTurn'] ?? 0;

        $startingElfData = $state->data['startingElf'];
        $startingElf = $startingElfData !== null ? new CustomCard(
            $startingElfData['color'],
            $startingElfData['value']
        ) : null;

        $players = PlayerViews::build($state, static fn (Player $player): array => [
            'cardCount' => $table->hand($player->id)->count(),
        ]);

        $hand = [];
        if (null !== $viewerId && $table->has('hand:'.$viewerId)) {
            foreach ($table->hand($viewerId)->items as $index => $card) {
                $hand[] = CustomCardPresenter::view($card) + [
                        'index' => $index,
                        'playable' => $myTurn && $this->rules->playable($card, $board, $startingElf),
                    ];
            }
        }

        $canDraw = $myTurn && ($cardsPlayed === 0) && ($drawCount < 3) && !$table->zone('stock')->isEmpty();
        $canPass = $myTurn && ($cardsPlayed > 0 || $drawCount > 0);

        return [
            'myTurn' => $myTurn,
            'players' => $players,
            'board' => $board,
            'hand' => $hand,
            'stockCount' => $table->zone('stock')->count(),
            'canDraw' => $canDraw,
            'canPass' => $canPass,
            'cardsPlayed' => $cardsPlayed,
            'drawCount' => $drawCount,
            'startingElf' => $startingElfData,
        ];
    }
}
