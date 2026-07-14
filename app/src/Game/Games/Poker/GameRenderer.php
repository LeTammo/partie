<?php

declare(strict_types=1);

namespace App\Game\Games\Poker;

use App\Game\Core\Card\CardPresenter;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
use App\Game\Core\Model\Player;
use App\Game\Core\View\ChipViews;
use App\Game\Core\View\PlayerViews;

final readonly class GameRenderer
{
    private const array BETTING_PHASES = ['preflop', 'flop', 'turn', 'river'];

    /**
     * @return array<string, mixed>
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $table = $state->table;
        $running = GameStatus::Running === $state->status;
        $phase = $state->data['phase'];
        $myTurn = $state->isViewersTurn($viewerId) && \in_array($phase, self::BETTING_PHASES, true);
        $revealHands = \in_array($phase, ['showdown', 'handend'], true);
        $pot = array_sum($state->data['contributed'] ?? []);

        $resultByPlayer = [];
        foreach ($state->data['lastResult'] ?? [] as $result) {
            $resultByPlayer[$result['playerId']] = ($resultByPlayer[$result['playerId']] ?? 0) + $result['amount'];
        }

        $players = PlayerViews::build($state, function (Player $player) use ($state, $table, $viewerId, $revealHands): array {
            $dealtIn = $state->data['dealtIn'][$player->id] ?? false;
            $folded = $state->data['folded'][$player->id] ?? true;
            $showCards = $dealtIn && (($player->id === $viewerId) || ($revealHands && !$folded));

            return [
                'chips' => $state->data['chips'][$player->id],
                'chipStack' => ChipViews::stack($state->data['chips'][$player->id], maxChips: 4),
                'bet' => $state->data['bets'][$player->id] ?? 0,
                'folded' => $folded,
                'allIn' => $state->data['allIn'][$player->id] ?? false,
                'dealtIn' => $dealtIn,
                'busted' => 0 === $state->data['chips'][$player->id],
                'isDealer' => $player->seat === $state->data['dealerSeat'],
                'cards' => $showCards ? CardPresenter::views($table->hand($player->id)->items) : array_fill(0, $dealtIn ? 2 : 0, ['back' => true]),
                'current' => GameStatus::Running === $state->status && \in_array($state->data['phase'], self::BETTING_PHASES, true) && $state->currentPlayer()->id === $player->id,
            ];
        });

        $betOptions = [];
        $callAmount = 0;
        $minRaiseTo = 0;
        $maxRaiseTo = 0;
        if ($myTurn && null !== $viewerId) {
            $currentBet = $state->data['currentBet'];
            $callAmount = min($currentBet - $state->data['bets'][$viewerId], $state->data['chips'][$viewerId]);
            $bigBlind = 2 * max(1, (int) ($this->smallBlindAmount($state) ?? 1));
            $minRaiseTo = $currentBet + max(1, \intdiv($bigBlind, 2));
            $maxRaiseTo = $state->data['chips'][$viewerId] + $state->data['bets'][$viewerId];

            $raise_options = 'preflop' === $phase
                ? $this->preflopRaiseOptions($bigBlind)
                : $this->postflopRaiseOptions($pot, $callAmount, $currentBet);

            foreach ($raise_options as $amount) {
                $to = max($amount, $minRaiseTo);
                if ($to < $maxRaiseTo) {
                    $betOptions[] = $to;
                }
            }

            $betOptions = array_unique($betOptions);
        }

        return [
            'phase' => $phase,
            'myTurn' => $myTurn,
            'players' => $players,
            'community' => CardPresenter::views($table->zone('community')->items),
            'pot' => $pot,
            'callAmount' => $callAmount,
            'canCheck' => 0 === $callAmount,
            'canRaise' => $myTurn && $maxRaiseTo > $state->data['currentBet'] && $maxRaiseTo > ($state->data['bets'][$viewerId] ?? 0),
            'betOptions' => $betOptions,
            'minRaiseTo' => $minRaiseTo,
            'maxRaiseTo' => $maxRaiseTo,
            'results' => $this->resultViews($state),
            'autoPending' => $running && \in_array($phase, ['dealflop', 'dealturn', 'dealriver', 'showdown'], true),
            'autoStep' => $state->data['autoStep'] ?? 0,
            'canStartNextRound' => $running && 'handend' === $phase && null !== $viewerId && null !== $state->playerById($viewerId),
        ];
    }

    /** @return array<int> */
    private function postflopRaiseOptions(int $pot, int $callAmount, int $currentBet): array
    {
        $potAfterCall = $pot + $callAmount;

        return [
            $currentBet + (int) round(0.5 * $potAfterCall),
            $currentBet + (int) round(0.75 * $potAfterCall),
            $currentBet + $potAfterCall,
        ];
    }

    /**
     * @return array<int>
     */
    private function preflopRaiseOptions(int $bigBlind): array
    {
        return [
            2 * $bigBlind,
            (int) round(2.5 * $bigBlind),
            3 * $bigBlind,
        ];
    }

    /**
     * @return list<array{player: string, amount: int}>
     */
    private function resultViews(GameState $state): array
    {
        $views = [];
        foreach ($state->data['lastResult'] ?? [] as $result) {
            $player = $state->playerById($result['playerId']);
            if (null === $player) {
                continue;
            }
            $views[] = ['player' => $player->nickname, 'amount' => $result['amount']];
        }

        return $views;
    }

    private function smallBlindAmount(GameState $state): ?int
    {
        $settings = $state->data['settings'] ?? [];

        return isset($settings['smallBlind']) ? (int) $settings['smallBlind'] : null;
    }
}
