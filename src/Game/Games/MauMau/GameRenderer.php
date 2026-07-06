<?php

declare(strict_types=1);

namespace App\Game\Games\MauMau;

use App\Game\Core\Card\CardPresenter;
use App\Game\Core\Card\Rank;
use App\Game\Core\Card\Suit;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;

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
        $running = GameStatus::Running === $state->status;
        $myTurn = null !== $viewerId && $running && $state->isPlayersTurn($viewerId);
        $top = $state->data['discard'][array_key_last($state->data['discard'])];
        $pendingDraw = $state->data['pendingDraw'];
        $wishedSuit = $state->data['wishedSuit'];

        $players = [];
        foreach ($state->players as $player) {
            $players[] = [
                'nickname' => $player->nickname,
                'color' => $player->color,
                'cardCount' => \count($state->data['hands'][$player->id]),
                'current' => $running && $state->currentPlayer()->id === $player->id,
            ];
        }

        $hand = [];
        if (null !== $viewerId && isset($state->data['hands'][$viewerId])) {
            foreach ($state->data['hands'][$viewerId] as $index => $card) {
                $hand[] = CardPresenter::view($card) + [
                    'index' => $index,
                    'playable' => $myTurn && $this->rules->playable($card, $top, $wishedSuit, $pendingDraw),
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
            'drawCount' => \count($state->data['drawPile']),
            'hand' => $hand,
            'hasDrawn' => $state->data['hasDrawn'],
            'canPass' => $myTurn && $state->data['hasDrawn'],
            'canDraw' => $myTurn && ($pendingDraw > 0 || !$state->data['hasDrawn']),
            'suits' => $suits,
        ];
    }
}
