<?php

declare(strict_types=1);

namespace App\Game\Games\Durak;

use App\Game\Core\Card\CardPresenter;
use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Suit;
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
        $attackerId = $state->data['attackerId'];
        $defenderId = $state->data['defenderId'];
        $trumpSuit = Suit::from($state->data['trumpSuit']);
        $attackZone = $table->zone('attack');
        $stock = $table->zone('stock');

        $players = PlayerViews::build($state, static fn (Player $player): array => [
            'cardCount' => $table->hand($player->id)->count(),
            'isAttacker' => $player->id === $attackerId,
            'isDefender' => $player->id === $defenderId,
        ]);

        $openPairIndex = null;
        foreach ($attackZone->items as $i => $pair) {
            if (null === $pair['defend']) {
                $openPairIndex = $i;
                break;
            }
        }

        $iAmAttacker = $viewerId === $attackerId;
        $iAmDefender = $viewerId === $defenderId;

        $hand = [];
        if (null !== $viewerId && $table->has('hand:'.$viewerId)) {
            foreach ($table->hand($viewerId)->items as $index => $card) {
                /** @var PlayingCard $card */
                $playableAttack = $myTurn && $iAmAttacker && null === $openPairIndex
                    && \count($attackZone->items) < min(GameRules::MAX_ATTACK_CARDS, $table->hand($defenderId)->count())
                    && $this->rules->canAttackWith($card, $attackZone->items);
                $playableDefend = $myTurn && $iAmDefender && null !== $openPairIndex
                    && $this->rules->beats($card, $attackZone->items[$openPairIndex]['attack'], $trumpSuit);

                $hand[] = CardPresenter::view($card) + [
                    'index' => $index,
                    'trump' => $card->suit === $trumpSuit,
                    'playableAttack' => $playableAttack,
                    'playableDefend' => $playableDefend,
                ];
            }
        }

        $pairs = [];
        foreach ($attackZone->items as $i => $pair) {
            $pairs[] = [
                'index' => $i,
                'attack' => CardPresenter::view($pair['attack']),
                'defend' => null !== $pair['defend'] ? CardPresenter::view($pair['defend']) : null,
            ];
        }

        return [
            'myTurn' => $myTurn,
            'players' => $players,
            'iAmAttacker' => $iAmAttacker,
            'iAmDefender' => $iAmDefender,
            'trumpSuit' => $trumpSuit->symbol(),
            'trumpRed' => $trumpSuit->isRed(),
            'trumpCard' => $stock->count() > 0 ? CardPresenter::view($stock->items[0]) : null,
            'stockCount' => $stock->count(),
            'pairs' => $pairs,
            'openPairIndex' => $openPairIndex,
            'hand' => $hand,
            'canDone' => $myTurn && $iAmAttacker && $this->rules->allDefended($attackZone->items),
            'canTake' => $myTurn && $iAmDefender && null !== $openPairIndex,
        ];
    }
}
