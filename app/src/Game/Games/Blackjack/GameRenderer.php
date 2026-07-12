<?php

declare(strict_types=1);

namespace App\Game\Games\Blackjack;

use App\Game\Core\Card\CardPresenter;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Player;
use App\Game\Core\View\ChipViews;
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
        $running = GameStatus::Running === $state->status;
        $phase = $state->data['phase'];
        $myTurn = $state->isViewersTurn($viewerId) && \in_array($phase, ['betting', 'playing'], true);
        $holeCardHidden = 'playing' === $phase
            || ('dealer' === $phase && !($state->data['dealerRevealed'] ?? true));

        $players = PlayerViews::build($state, function (Player $player) use ($state): array {
            $hand = $state->table->hand($player->id)->items;
            $bet = $state->data['bets'][$player->id];

            return [
                'chips' => $state->data['chips'][$player->id],
                'chipStack' => ChipViews::stack($state->data['chips'][$player->id], maxChips: 4),
                'bet' => $bet,
                'cards' => CardPresenter::views($hand),
                'value' => [] !== $hand ? $this->rules->value($hand) : null,
                'bust' => [] !== $hand && $this->rules->isBust($hand),
                'blackjack' => $this->rules->isBlackjack($hand),
                'broke' => $state->data['chips'][$player->id] < GameRules::MIN_BET && null === $bet,
            ];
        });

        $dealerHand = $state->table->zone('dealer')->items;
        $dealerCards = [];
        foreach ($dealerHand as $i => $card) {
            $dealerCards[] = $i > 0 && $holeCardHidden
                ? ['back' => true]
                : CardPresenter::view($card);
        }

        $betOptions = [];
        $canDouble = false;
        if ($myTurn && 'betting' === $phase) {
            $chips = $state->data['chips'][$viewerId];
            $betOptions = array_map(
                static fn (int $amount): array => ['amount' => $amount, 'chip' => ChipViews::single($amount)],
                array_values(array_filter(
                    GameRules::BET_OPTIONS,
                    static fn (int $amount): bool => $amount <= $chips,
                )),
            );
        }
        if ($myTurn && 'playing' === $phase) {
            $canDouble = 2 === $state->table->hand($viewerId)->count()
                && $state->data['chips'][$viewerId] >= $state->data['bets'][$viewerId];
        }

        return [
            'round' => $state->data['round'],
            'roundsTotal' => $state->data['roundsTotal'],
            'phase' => $phase,
            'myTurn' => $myTurn,
            'players' => $players,
            'dealer' => $dealerCards,
            'dealerUpValue' => [] !== $dealerHand
                ? $this->rules->value($holeCardHidden ? [$dealerHand[0]] : $dealerHand)
                : null,
            'dealerActing' => \in_array($phase, ['dealer', 'settle'], true),
            'autoPending' => $running && \in_array($phase, ['dealer', 'settle', 'roundend'], true),
            'autoStep' => $state->data['autoStep'] ?? 0,
            'betOptions' => $betOptions,
            'canDouble' => $canDouble,
        ];
    }
}
