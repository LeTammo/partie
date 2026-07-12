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
        $table = $state->table;
        $moves = $this->computeMoves($state);

        $tableau = [];
        foreach ($table->matching('tableau:') as $zone) {
            $cards = [];
            foreach ($zone->items as $index => $slot) {
                $cards[] = [
                    'card' => CardPresenter::view($slot['card']),
                    'faceUp' => $slot['faceUp'],
                    'source' => $slot['faceUp'] ? $zone->key.':'.$index : null,
                ];
            }
            $tableau[] = ['zone' => $zone->key, 'cards' => $cards];
        }

        $foundations = [];
        foreach (Suit::cases() as $suit) {
            $zone = $table->zone('foundation:'.$suit->value);
            $top = $zone->top();
            $foundations[] = [
                'suit' => $suit->symbol(),
                'red' => $suit->isRed(),
                'zone' => $zone->key,
                'top' => null !== $top ? CardPresenter::view($top) : null,
            ];
        }

        $wasteTop = $table->zone('waste')->top();

        return [
            'tableau' => $tableau,
            'foundations' => $foundations,
            'waste' => null !== $wasteTop ? CardPresenter::view($wasteTop) : null,
            'wasteSource' => null !== $wasteTop ? 'waste' : null,
            'stockCount' => $table->zone('stock')->count(),
            'canDraw' => !$table->zone('stock')->isEmpty() || !$table->zone('waste')->isEmpty(),
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
        $table = $state->table;

        $wasteTop = $table->zone('waste')->top();
        if (null !== $wasteTop) {
            $dests = $this->destinationsFor($state, $wasteTop, null, true);
            if ([] !== $dests) {
                $moves['waste'] = $dests;
            }
        }

        foreach ($table->matching('tableau:') as $zone) {
            $lastIndex = array_key_last($zone->items);
            foreach ($zone->items as $index => $slot) {
                if (!$slot['faceUp']) {
                    continue;
                }
                $col = (int) explode(':', $zone->key)[1];
                $dests = $this->destinationsFor($state, $slot['card'], $col, $index === $lastIndex);
                if ([] !== $dests) {
                    $moves[$zone->key.':'.$index] = $dests;
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
        foreach ($state->table->matching('tableau:') as $zone) {
            $col = (int) explode(':', $zone->key)[1];
            if ($col === $excludeColumn) {
                continue;
            }
            if ($this->rules->canDropOnTableau($card, $zone->items)) {
                $dests[] = $zone->key;
            }
        }

        if ($includeFoundation && $this->rules->canDropOnFoundation($card, $state->table->zone('foundation:'.$card->suit->value)->items)) {
            $dests[] = 'foundation:'.$card->suit->value;
        }

        return $dests;
    }
}
