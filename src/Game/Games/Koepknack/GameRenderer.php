<?php

declare(strict_types=1);

namespace App\Game\Games\Koepknack;

use App\Game\Core\Card\CardPresenter;
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

        $players = [];
        foreach ($state->players as $player) {
            $players[] = [
                'nickname' => $player->nickname,
                'color' => $player->color,
                'lives' => $state->data['lives'][$player->id],
                'swimming' => $state->data['swimming'][$player->id],
                'eliminated' => $state->data['eliminated'][$player->id],
                'current' => $running && $state->currentPlayer()->id === $player->id,
            ];
        }

        $hand = null;
        $handValue = null;
        if (null !== $viewerId
            && isset($state->data['hands'][$viewerId])
            && !$state->data['eliminated'][$viewerId]) {
            $cards = $state->data['hands'][$viewerId];
            $hand = CardPresenter::views($cards);
            $handValue = $this->rules->format($this->rules->value($cards));
        }

        $closerId = $state->data['closerId'];

        return [
            'round' => $state->data['round'],
            'players' => $players,
            'middle' => CardPresenter::views($state->data['middle']),
            'hand' => $hand,
            'handValue' => $handValue,
            'closed' => null !== $closerId,
            'closerName' => null !== $closerId ? $state->playerById($closerId)?->nickname : null,
            'canAct' => $myTurn,
            'canClose' => $myTurn && null === $closerId,
            'deckCount' => \count($state->data['deck']),
        ];
    }
}
