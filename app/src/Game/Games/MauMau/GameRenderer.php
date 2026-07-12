<?php

declare(strict_types=1);

namespace App\Game\Games\MauMau;

use App\Game\Core\Card\CardPresenter;
use App\Game\Core\Card\Rank;
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
        $top = $table->zone('discard')->top();
        $pendingDraw = $state->data['pendingDraw'];
        $pendingSkip = $state->data['pendingSkip'] ?? 0;
        $wishedSuit = $state->data['wishedSuit'];
        $penaltyLocked = $state->data['penaltyLocked'] ?? false;
        $options = Options::fromState($state);

        $players = PlayerViews::build($state, static fn (Player $player): array => [
            'cardCount' => $table->hand($player->id)->count(),
        ]);

        $hand = [];
        if (null !== $viewerId && $table->has('hand:'.$viewerId)) {
            foreach ($table->hand($viewerId)->items as $index => $card) {
                $hand[] = CardPresenter::view($card) + [
                    'index' => $index,
                    'playable' => $myTurn && $this->rules->playable($card, $top, $wishedSuit, $pendingDraw, $penaltyLocked, $pendingSkip, $options),
                    'isJack' => Rank::Jack === $card->rank,
                ];
            }
        }

        $suits = [];
        foreach (Suit::cases() as $suit) {
            $suits[] = ['value' => $suit->value, 'symbol' => $suit->symbol(), 'red' => $suit->isRed()];
        }

        return [
            'myTurn' => $myTurn,
            'players' => $players,
            'top' => CardPresenter::view($top),
            'wishedSuit' => null !== $wishedSuit ? Suit::from($wishedSuit)->symbol() : null,
            'wishedSuitRed' => null !== $wishedSuit && Suit::from($wishedSuit)->isRed(),
            'pendingDraw' => $pendingDraw,
            'pendingSkip' => $pendingSkip,
            'penaltyLocked' => $penaltyLocked,
            'drawCount' => $table->zone('stock')->count(),
            'hand' => $hand,
            'hasDrawn' => $state->data['hasDrawn'],
            'canPass' => $myTurn && ($pendingSkip > 0 || $state->data['hasDrawn']),
            'canDraw' => $myTurn && $pendingSkip <= 0 && ($pendingDraw > 0 || !$state->data['hasDrawn']),
            'suits' => $suits,
        ];
    }
}
