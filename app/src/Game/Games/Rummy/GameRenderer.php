<?php

declare(strict_types=1);

namespace App\Game\Games\Rummy;

use App\Game\Core\Card\CardPresenter;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\Player;
use App\Game\Core\View\PlayerViews;

final class GameRenderer
{
    /**
     * @return array<string, mixed>
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $table = $state->table;
        $myTurn = $state->isViewersTurn($viewerId);

        $players = PlayerViews::build($state, static fn (Player $player): array => [
            'cardCount' => $table->hand($player->id)->count(),
            'hasMelded' => $state->data['hasMelded'][$player->id],
        ]);

        $melds = [];
        foreach ($table->matching('meld:') as $zone) {
            $melds[] = [
                'key' => $zone->key,
                'owner' => $state->playerById($zone->ownerId)?->nickname,
                'type' => $zone->meta['type'],
                'cards' => CardPresenter::views($zone->items),
            ];
        }

        $hand = [];
        if (null !== $viewerId && $table->has('hand:'.$viewerId)) {
            foreach ($table->hand($viewerId)->items as $index => $card) {
                $hand[] = CardPresenter::view($card) + ['index' => $index];
            }
        }

        $discardTop = $table->zone('discard')->top();
        $hasOpened = null !== $viewerId && ($state->data['hasMelded'][$viewerId] ?? false);

        return [
            'myTurn' => $myTurn,
            'players' => $players,
            'melds' => $melds,
            'hand' => $hand,
            'stockCount' => $table->zone('stock')->count(),
            'discardTop' => null !== $discardTop ? CardPresenter::view($discardTop) : null,
            'hasDrawn' => $state->data['hasDrawn'],
            'canDraw' => $myTurn && !$state->data['hasDrawn'],
            'canAct' => $myTurn && $state->data['hasDrawn'],
            'canLayoff' => $myTurn && $state->data['hasDrawn'] && $hasOpened,
            'canTakeback' => $myTurn && [] !== $state->data['turnMelds'],
            'hasOpened' => $hasOpened,
            'initialPoints' => $state->data['turnMeldPoints'],
            'initialMeldPoints' => (int) ($state->data['settings']['initialMeldPoints'] ?? GameRules::INITIAL_MELD_POINTS),
        ];
    }
}
