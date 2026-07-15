<?php

declare(strict_types=1);

namespace App\Game\Games\ElevenRush;

use App\Game\Core\Card\CustomCard;
use App\Game\Core\Card\DeckFactory;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Service\AbstractGameDefinition;
use App\Game\Core\Zone\Table;
use App\Game\Core\Zone\Zone;
use App\Game\Core\Zone\ZoneVisibility;

final readonly class GameDefinition extends AbstractGameDefinition
{
    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'elevenrush';
    }

    public function getName(): string
    {
        return 'game.elevenrush.name';
    }

    public function getDescription(): string
    {
        return 'game.elevenrush.description';
    }

    public function getIcon(): string
    {
        return 'games/elevenrush';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 6;
    }

    public function settings(): array
    {
        return [];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;
        $table = $state->table = new Table();

        $colors = ['red', 'yellow', 'green', 'blue'];
        $deck = DeckFactory::customRangeDeck($colors, 1, 20);

        $numPlayers = count($players);
        $stockSize = $numPlayers === 2 ? 40 : 20;
        $totalDealt = 80 - $stockSize;
        $handSize = (int) ($totalDealt / $numPlayers);

        $hands = [];
        foreach ($players as $player) {
            $playerHand = array_splice($deck, 0, $handSize);
            $this->sortCards($playerHand);

            $table->add(new Zone('hand:'.$player->id, $player->id, ZoneVisibility::Owner))
                ->push(...$playerHand);
            $hands[$player->id] = $playerHand;
        }

        $table->add(new Zone('stock', visibility: ZoneVisibility::Hidden))->push(...$deck);

        $startingElf = $this->rules->getStartingElf($hands);
        $startingPlayerId = $players[0]->id;

        if ($startingElf !== null) {
            foreach ($players as $player) {
                foreach ($table->hand($player->id)->items as $card) {
                    if ($card->color === $startingElf->color && $card->value === $startingElf->value) {
                        $startingPlayerId = $player->id;
                        break 2;
                    }
                }
            }
        }

        while ($state->currentPlayer()->id !== $startingPlayerId) {
            $state->advanceTurn();
        }

        $state->data['board'] = [
            'red' => ['min' => null, 'max' => null],
            'yellow' => ['min' => null, 'max' => null],
            'green' => ['min' => null, 'max' => null],
            'blue' => ['min' => null, 'max' => null],
        ];

        $state->data['startingElf'] = $startingElf ? [
            'color' => $startingElf->color,
            'value' => $startingElf->value,
        ] : null;

        $state->data['cardsPlayedThisTurn'] = 0;
        $state->data['drawCountThisTurn'] = 0;

        $state->logGameEvent('log.elevenrush.started');

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'play' => $this->play($state, $this->intParam($payload, 'card')),
            'draw' => $this->draw($state),
            'pass' => $this->pass($state),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/elevenrush/table.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function play(GameState $state, int $cardIndex): void
    {
        $player = $state->currentPlayer();
        $hand = $state->table->hand($player->id);

        if (!isset($hand->items[$cardIndex])) {
            $this->invalidMove('error.elevenrush.unknown_card');
        }

        /** @var CustomCard $card */
        $card = $hand->items[$cardIndex];

        $startingElfData = $state->data['startingElf'];
        $startingElf = $startingElfData !== null ? new CustomCard(
            $startingElfData['color'],
            $startingElfData['value']
        ) : null;

        if (!$this->rules->playable($card, $state->data['board'], $startingElf)) {
            $this->invalidMove('error.elevenrush.not_playable');
        }

        $hand->removeAt($cardIndex);

        $colorKey = $card->color;
        $value = (int) $card->value;
        if ($value === 11) {
            $state->data['board'][$colorKey] = ['min' => 11, 'max' => 11];
        } else {
            $min = $state->data['board'][$colorKey]['min'];
            $max = $state->data['board'][$colorKey]['max'];
            if ($value === $min - 1) {
                $state->data['board'][$colorKey]['min'] = $value;
            } elseif ($value === $max + 1) {
                $state->data['board'][$colorKey]['max'] = $value;
            }
        }

        $state->data['startingElf'] = null;
        $state->data['cardsPlayedThisTurn']++;

        $state->logGameEvent('log.elevenrush.played', [
            '%player%' => $player->nickname,
            '%color%' => 't:elevenrush.color.'.$card->color,
            '%value%' => $card->value,
        ]);

        if ($hand->isEmpty()) {
            $state->finish($player->id);
            $state->logGameEvent('log.elevenrush.won', ['%player%' => $player->nickname]);
        }
    }

    private function draw(GameState $state): void
    {
        $cardsPlayed = $state->data['cardsPlayedThisTurn'] ?? 0;
        $drawCount = $state->data['drawCountThisTurn'] ?? 0;

        if ($cardsPlayed > 0) {
            $this->invalidMove('error.elevenrush.cannot_draw_after_play');
        }

        if ($drawCount >= 3) {
            $this->invalidMove('error.elevenrush.draw_limit_reached');
        }

        $stock = $state->table->zone('stock');
        if ($stock->isEmpty()) {
            $this->invalidMove('error.elevenrush.stock_empty');
        }

        $player = $state->currentPlayer();
        $drawnCard = $stock->pop();
        $hand = $state->table->hand($player->id);
        $hand->push($drawnCard);

        $items = $hand->items;
        $this->sortCards($items);
        $hand->clear();
        $hand->push(...$items);

        $state->data['drawCountThisTurn']++;

        $state->logGameEvent('log.elevenrush.drew', [
            '%player%' => $player->nickname,
        ]);
    }

    private function pass(GameState $state): void
    {
        $cardsPlayed = $state->data['cardsPlayedThisTurn'] ?? 0;
        $drawCount = $state->data['drawCountThisTurn'] ?? 0;

        if ($cardsPlayed === 0 && $drawCount === 0) {
            $this->invalidMove('error.elevenrush.must_act');
        }

        $state->logGameEvent('log.elevenrush.passed', [
            '%player%' => $state->currentPlayer()->nickname,
            '%count%' => $cardsPlayed,
        ]);

        $state->data['cardsPlayedThisTurn'] = 0;
        $state->data['drawCountThisTurn'] = 0;
        $state->advanceTurn();
    }

    /**
     * @param array<int, CustomCard> $cards
     */
    private function sortCards(array &$cards): void
    {
        usort($cards, static function (CustomCard $a, CustomCard $b) {
            if ($a->color === $b->color) {
                return (int) $a->value <=> (int) $b->value;
            }
            return $a->color <=> $b->color;
        });
    }
}
