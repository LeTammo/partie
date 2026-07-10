<?php

declare(strict_types=1);

namespace App\Game\Games\Solitaire;

use App\Game\Core\Card\CardPresenter;
use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Suit;
use App\Game\Core\Model\GameState;

final readonly class GameRenderer
{
    public function __construct(private GameRules $rules)
    {
    }

    /**
     * @return array{
     *     tableau: list<array{zone: string, cards: list<array{card: array, faceUp: bool, source: ?string}>}>,
     *     foundations: list<array{suit: string, red: bool, zone: string, top: ?array}>,
     *     waste: ?array, wasteSource: ?string,
     *     stockCount: int, canDraw: bool, myTurn: bool,
     *     moves: array<string, list<string>>
     * }
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $moves = $this->computeMoves($state);

        $tableau = [];
        foreach ($state->data['tableau'] as $col => $pile) {
            $cards = [];
            foreach ($pile as $index => $slot) {
                $cards[] = [
                    'card' => CardPresenter::view($slot['card']),
                    'faceUp' => $slot['faceUp'],
                    'source' => $slot['faceUp'] ? "tableau:$col:$index" : null,
                ];
            }
            $tableau[] = ['zone' => "tableau:$col", 'cards' => $cards];
        }

        $foundations = [];
        foreach (Suit::cases() as $suit) {
            $pile = $state->data['foundations'][$suit->value];
            $foundations[] = [
                'suit' => $suit->symbol(),
                'red' => $suit->isRed(),
                'zone' => 'foundation:'.$suit->value,
                'top' => [] !== $pile ? CardPresenter::view($pile[array_key_last($pile)]) : null,
            ];
        }

        $waste = $state->data['waste'];

        return [
            'tableau' => $tableau,
            'foundations' => $foundations,
            'waste' => [] !== $waste ? CardPresenter::view($waste[array_key_last($waste)]) : null,
            'wasteSource' => [] !== $waste ? 'waste' : null,
            'stockCount' => \count($state->data['stock']),
            'canDraw' => [] !== $state->data['stock'] || [] !== $state->data['waste'],
            'myTurn' => $state->isViewersTurn($viewerId),
            'moves' => $moves,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function computeMoves(GameState $state): array
    {
        $moves = [];

        $waste = $state->data['waste'];
        if ([] !== $waste) {
            $dests = $this->destinationsFor($state, $waste[array_key_last($waste)], null, true);
            if ([] !== $dests) {
                $moves['waste'] = $dests;
            }
        }

        foreach ($state->data['tableau'] as $col => $pile) {
            $lastIndex = array_key_last($pile);
            foreach ($pile as $index => $slot) {
                if (!$slot['faceUp']) {
                    continue;
                }
                $dests = $this->destinationsFor($state, $slot['card'], $col, $index === $lastIndex);
                if ([] !== $dests) {
                    $moves["tableau:$col:$index"] = $dests;
                }
            }
        }

        return $moves;
    }

    /**
     * @return list<string>
     */
    private function destinationsFor(GameState $state, PlayingCard $card, ?int $excludeColumn, bool $includeFoundation): array
    {
        $dests = [];
        foreach ($state->data['tableau'] as $col => $pile) {
            if ($col === $excludeColumn) {
                continue;
            }
            if ($this->rules->canDropOnTableau($card, $pile)) {
                $dests[] = "tableau:$col";
            }
        }

        if ($includeFoundation && $this->rules->canDropOnFoundation($card, $state->data['foundations'][$card->suit->value])) {
            $dests[] = 'foundation:'.$card->suit->value;
        }

        return $dests;
    }
}
