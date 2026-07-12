<?php

declare(strict_types=1);

namespace App\Game\Games\Yahtzee;

use App\Game\Core\Model\Dice;
use App\Game\Core\Model\GameState;
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
        $myTurn = $state->isViewersTurn($viewerId);
        $hasRolled = (bool) $state->data['hasRolled'];
        $rollsLeft = (int) $state->data['rollsLeft'];
        $values = array_map(static fn (Dice $d): int => $d->value, $state->dice);

        $dice = [];
        foreach ($state->dice as $i => $die) {
            $dice[] = [
                'index' => $i,
                'value' => $die->value,
                'locked' => $die->locked,
                'lockable' => $myTurn && $hasRolled && $rollsLeft > 0,
            ];
        }

        $rows = [];
        foreach (GameRules::allCategories() as $category) {
            $cells = [];
            foreach ($state->players as $player) {
                $score = $state->data['scorecards'][$player->id][$category];
                $isViewer = $player->id === $viewerId;
                $cells[] = [
                    'score' => $score,
                    'potential' => $isViewer && $myTurn && $hasRolled && null === $score
                        ? $this->rules->score($category, $values)
                        : null,
                ];
            }
            $rows[] = [
                'category' => $category,
                'upper' => \in_array($category, GameRules::UPPER_CATEGORIES, true),
                'cells' => $cells,
            ];
        }

        $upperBonusValues = [];
        $totals = [];
        foreach ($state->players as $player) {
            $card = $state->data['scorecards'][$player->id];
            $upper = $this->rules->upperSubtotal($card);
            $bonus = $upper >= GameRules::UPPER_BONUS_THRESHOLD ? GameRules::UPPER_BONUS : 0;
            $upperBonusValues[] = $upper.($bonus > 0 ? ' +'.$bonus : '');
            $totals[] = $this->rules->total($card);
        }

        return [
            'dice' => $dice,
            'dieFace' => $state->currentPlayer()->color,
            'diePip' => '#faf7f2',
            'myTurn' => $myTurn,
            'hasRolled' => $hasRolled,
            'rollsLeft' => $rollsLeft,
            'canRoll' => $myTurn && $rollsLeft > 0,
            'canScore' => $myTurn && $hasRolled,
            'players' => PlayerViews::build($state),
            'rows' => $rows,
            'upperBonusValues' => $upperBonusValues,
            'totals' => $totals,
        ];
    }
}
