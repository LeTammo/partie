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
        $myTurn = $state->isViewersTurn($viewerId);

        $players = PlayerViews::build($state, static fn (Player $player): array => [
            'cardCount' => \count($state->data['hands'][$player->id]),
            'hasMelded' => $state->data['hasMelded'][$player->id],
        ]);

        $melds = [];
        foreach ($state->data['melds'] as $index => $meld) {
            $melds[] = [
                'index' => $index,
                'owner' => $state->playerById($meld['ownerId'])?->nickname,
                'type' => $meld['type'],
                'cards' => CardPresenter::views($meld['cards']),
            ];
        }

        $hand = [];
        if (null !== $viewerId && isset($state->data['hands'][$viewerId])) {
            foreach ($state->data['hands'][$viewerId] as $index => $card) {
                $hand[] = CardPresenter::view($card) + ['index' => $index];
            }
        }

        $discard = $state->data['discard'];
        $hasOpened = null !== $viewerId && ($state->data['hasMelded'][$viewerId] ?? false);

        return [
            'myTurn' => $myTurn,
            'players' => $players,
            'melds' => $melds,
            'hand' => $hand,
            'stockCount' => \count($state->data['stock']),
            'discardTop' => [] !== $discard
                ? CardPresenter::view($discard[array_key_last($discard)])
                : null,
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
