<?php

declare(strict_types=1);

namespace App\Game\Games\Blackjack;

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
        $phase = $state->data['phase'];
        $myTurn = null !== $viewerId && $running && $state->isPlayersTurn($viewerId)
            && \in_array($phase, ['betting', 'playing'], true);
        $holeCardHidden = 'playing' === $phase
            || ('dealer' === $phase && !($state->data['dealerRevealed'] ?? true));

        $players = [];
        foreach ($state->players as $player) {
            $hand = $state->data['hands'][$player->id];
            $bet = $state->data['bets'][$player->id];
            $players[] = [
                'nickname' => $player->nickname,
                'color' => $player->color,
                'chips' => $state->data['chips'][$player->id],
                'bet' => $bet,
                'cards' => CardPresenter::views($hand),
                'value' => [] !== $hand ? $this->rules->value($hand) : null,
                'bust' => [] !== $hand && $this->rules->isBust($hand),
                'blackjack' => $this->rules->isBlackjack($hand),
                'broke' => $state->data['chips'][$player->id] < GameRules::MIN_BET && null === $bet,
                'current' => $running && $state->currentPlayer()->id === $player->id,
            ];
        }

        $dealerCards = [];
        foreach ($state->data['dealer'] as $i => $card) {
            $dealerCards[] = $i > 0 && $holeCardHidden
                ? ['back' => true]
                : CardPresenter::view($card);
        }

        $betOptions = [];
        $canDouble = false;
        if ($myTurn && 'betting' === $phase) {
            $chips = $state->data['chips'][$viewerId];
            $betOptions = array_values(array_filter(
                GameRules::BET_OPTIONS,
                static fn (int $amount): bool => $amount <= $chips,
            ));
        }
        if ($myTurn && 'playing' === $phase) {
            $canDouble = 2 === \count($state->data['hands'][$viewerId])
                && $state->data['chips'][$viewerId] >= $state->data['bets'][$viewerId];
        }

        return [
            'round' => $state->data['round'],
            'roundsTotal' => $state->data['roundsTotal'],
            'phase' => $phase,
            'myTurn' => $myTurn,
            'players' => $players,
            'dealer' => $dealerCards,
            'dealerUpValue' => [] !== $state->data['dealer']
                ? $this->rules->value($holeCardHidden ? [$state->data['dealer'][0]] : $state->data['dealer'])
                : null,
            'dealerActing' => \in_array($phase, ['dealer', 'settle'], true),
            'autoPending' => $running && \in_array($phase, ['dealer', 'settle', 'roundend'], true),
            'autoStep' => $state->data['autoStep'] ?? 0,
            'betOptions' => $betOptions,
            'canDouble' => $canDouble,
        ];
    }
}
