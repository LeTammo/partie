<?php

declare(strict_types=1);

namespace App\Game\Games\Koepknack;

use App\Game\Core\Card\CardPresenter;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Player;
use App\Game\Core\View\PlayerViews;

final readonly class GameRenderer
{
    public function __construct(private GameRules $rules)
    {
    }

    /** @return array<string, mixed> */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $table = $state->table;
        $running = GameStatus::Running === $state->status;
        $phase = $state->data['phase'] ?? 'playing';
        $roundEnd = $running && 'roundend' === $phase;
        $myTurn = $state->isViewersTurn($viewerId) && 'playing' === $phase;

        $players = PlayerViews::build($state, static fn (Player $player): array => [
            'points' => $state->data['points'][$player->id],
            'current' => $running && 'playing' === $phase && $state->currentPlayer()->id === $player->id,
        ]);

        $hand = null;
        $handValue = null;
        if (null !== $viewerId && $table->has('hand:'.$viewerId)) {
            $cards = $table->hand($viewerId)->items;
            $hand = CardPresenter::views($cards);
            $handValue = $this->rules->format($this->rules->value($cards));
        }

        $reveal = [];
        if ($roundEnd) {
            foreach ($state->players as $player) {
                // endRound() flipped every hand zone to ZoneVisibility::All
                $cards = $table->hand($player->id)->items;
                $reveal[] = [
                    'nickname' => $player->nickname,
                    'color' => $player->color,
                    'cards' => CardPresenter::views($cards),
                    'value' => $this->rules->format($this->rules->value($cards)),
                ];
            }
        }

        $closerId = $state->data['closerId'];

        return [
            'round' => $state->data['round'],
            'roundsTotal' => $state->data['roundsTotal'],
            'players' => $players,
            'middle' => CardPresenter::views($table->zone('middle')->items),
            'hand' => $hand,
            'handValue' => $handValue,
            'closed' => null !== $closerId,
            'closerName' => null !== $closerId ? $state->playerById($closerId)?->nickname : null,
            'canAct' => $myTurn,
            'canClose' => $myTurn && null === $closerId,
            'deckCount' => $table->zone('stock')->count(),
            'roundEnd' => $roundEnd,
            'reveal' => $reveal,
            'canStartNewRound' => $roundEnd && null !== $viewerId,
        ];
    }
}
