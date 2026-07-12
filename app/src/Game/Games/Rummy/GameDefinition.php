<?php

declare(strict_types=1);

namespace App\Game\Games\Rummy;

use App\Game\Core\Card\DeckFactory;
use App\Game\Core\Card\Piles;
use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameSetting;
use App\Game\Core\Model\GameSettingType;
use App\Game\Core\Model\GameState;
use App\Game\Core\Service\AbstractGameDefinition;
use App\Game\Core\Zone\Table;
use App\Game\Core\Zone\Zone;
use App\Game\Core\Zone\ZoneVisibility;

final readonly class GameDefinition extends AbstractGameDefinition
{
    private const int HAND_SIZE = 13;

    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'rummy';
    }

    public function getName(): string
    {
        return 'game.rummy.name';
    }

    public function getDescription(): string
    {
        return 'game.rummy.description';
    }

    public function getIcon(): string
    {
        return 'card-fan';
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
        return [
            new GameSetting(
                key: 'initialMeldPoints',
                labelKey: 'setting.rummy.initial_meld_points',
                type: GameSettingType::Int,
                default: GameRules::INITIAL_MELD_POINTS,
                min: 10,
                max: 100,
            ),
        ];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;
        $table = $state->table = new Table();

        $deck = DeckFactory::deck110();
        foreach ($players as $player) {
            $table->add(new Zone('hand:'.$player->id, $player->id, ZoneVisibility::Owner))
                ->push(...array_splice($deck, 0, self::HAND_SIZE));
            $state->data['hasMelded'][$player->id] = false;
        }
        $table->add(new Zone('discard'))->push(...array_splice($deck, 0, 1));
        $table->add(new Zone('stock', visibility: ZoneVisibility::Hidden))->push(...$deck);

        $state->data['meldSeq'] = 0;
        $state->data['hasDrawn'] = false;
        $state->data['turnMelds'] = [];
        $state->data['turnMeldPoints'] = 0;

        $state->logGameEvent('log.rummy.started', [
            '%points%' => (int) ($settings['initialMeldPoints'] ?? GameRules::INITIAL_MELD_POINTS),
        ]);

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'draw' => $this->draw($state),
            'takediscard' => $this->takeDiscard($state),
            'meld' => $this->meld($state, $this->intListParam($payload, 'cards')),
            'layoff' => $this->layoff($state, $this->intListParam($payload, 'cards'), $this->stringParam($payload, 'meld')),
            'discard' => $this->discard($state, $this->intListParam($payload, 'cards')),
            'takeback' => $this->takeback($state),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/rummy/table.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function draw(GameState $state): void
    {
        $this->assertNotDrawn($state);

        $drawn = Piles::draw(
            $state->table->zone('stock')->items,
            $state->table->zone('discard')->items,
            1,
            fn () => $state->logGameEvent('log.rummy.reshuffled'),
        );
        if ([] === $drawn) {
            $this->invalidMove('error.rummy.stock_empty');
        }

        $player = $state->currentPlayer();
        $state->table->hand($player->id)->push($drawn[0]);
        $state->data['hasDrawn'] = true;
        $state->logGameEvent('log.rummy.drew', ['%player%' => $player->nickname]);
    }

    private function takeDiscard(GameState $state): void
    {
        $this->assertNotDrawn($state);

        $discard = $state->table->zone('discard');
        if ($discard->isEmpty()) {
            $this->invalidMove('error.rummy.discard_empty');
        }

        $player = $state->currentPlayer();
        $state->table->hand($player->id)->push($discard->pop());
        $state->data['hasDrawn'] = true;
        $state->logGameEvent('log.rummy.took_discard', ['%player%' => $player->nickname]);
    }

    /**
     * @param list<int> $indexes
     */
    private function meld(GameState $state, array $indexes): void
    {
        $this->assertDrawn($state);
        $player = $state->currentPlayer();
        $hand = $state->table->hand($player->id);

        if (\count($indexes) < 3) {
            $this->invalidMove('error.rummy.select_meld');
        }
        $cards = $this->pick($hand->items, $indexes);
        if ($hand->count() - \count($indexes) < 1) {
            $this->invalidMove('error.rummy.keep_one');
        }

        $meld = $this->rules->validateMeld($cards);
        if (null === $meld) {
            $this->invalidMove('error.rummy.invalid_meld');
        }

        $this->remove($hand->items, $indexes);
        $zone = $state->table->add(new Zone('meld:'.$state->data['meldSeq']++, $player->id));
        $zone->meta['type'] = $meld['type'];
        $zone->push(...$cards);

        $state->logGameEvent('log.rummy.melded', [
            '%player%' => $player->nickname,
            '%points%' => $meld['points'],
        ]);

        if (!$state->data['hasMelded'][$player->id]) {
            $state->data['turnMelds'][] = $zone->key;
            $state->data['turnMeldPoints'] += $meld['points'];
            $initialMeldPoints = (int) ($this->setting($state, 'initialMeldPoints') ?? GameRules::INITIAL_MELD_POINTS);
            if ($state->data['turnMeldPoints'] >= $initialMeldPoints) {
                $state->data['hasMelded'][$player->id] = true;
                $state->data['turnMelds'] = [];
                $state->logGameEvent('log.rummy.opened', ['%player%' => $player->nickname, '%points%' => $initialMeldPoints]);
            }
        }
    }

    /**
     * @param list<int> $indexes
     */
    private function layoff(GameState $state, array $indexes, string $meldKey): void
    {
        $this->assertDrawn($state);
        $player = $state->currentPlayer();
        $hand = $state->table->hand($player->id);

        if (!$state->data['hasMelded'][$player->id]) {
            $this->invalidMove('error.rummy.open_first', ['%points%' => (int) ($this->setting($state, 'initialMeldPoints') ?? GameRules::INITIAL_MELD_POINTS)]);
        }
        if (1 !== \count($indexes)) {
            $this->invalidMove('error.rummy.select_one');
        }
        if (!str_starts_with($meldKey, 'meld:') || !$state->table->has($meldKey)) {
            $this->invalidMove('error.rummy.unknown_meld');
        }
        if ($hand->count() - 1 < 1) {
            $this->invalidMove('error.rummy.keep_one');
        }

        $card = $this->pick($hand->items, $indexes)[0];
        $meld = $state->table->zone($meldKey);
        $result = $this->rules->validateMeld([...$meld->items, $card]);
        if (null === $result || $result['type'] !== $meld->meta['type']) {
            $this->invalidMove('error.rummy.does_not_fit');
        }

        $this->remove($hand->items, $indexes);
        $meld->push($card);
        $state->logGameEvent('log.rummy.laid_off', ['%player%' => $player->nickname]);
    }

    /**
     * @param list<int> $indexes
     */
    private function discard(GameState $state, array $indexes): void
    {
        $this->assertDrawn($state);
        $player = $state->currentPlayer();
        $hand = $state->table->hand($player->id);

        if (1 !== \count($indexes)) {
            $this->invalidMove('error.rummy.select_one');
        }
        if (!$state->data['hasMelded'][$player->id] && $state->data['turnMeldPoints'] > 0) {
            $this->invalidMove('error.rummy.initial_meld', ['%points%' => (int) ($this->setting($state, 'initialMeldPoints') ?? GameRules::INITIAL_MELD_POINTS)]);
        }

        $card = $this->pick($hand->items, $indexes)[0];
        $this->remove($hand->items, $indexes);
        $state->table->zone('discard')->push($card);

        $state->logGameEvent('log.rummy.discarded', [
            '%player%' => $player->nickname,
            '%suit%' => $card->joker ? '' : $card->suit->symbol(),
            '%rank%' => $card->joker ? 't:card.joker' : 't:card.rank.'.$card->rank->labelKey(),
        ]);

        if ($hand->isEmpty()) {
            $state->finish($player->id);
            $state->logGameEvent('log.rummy.won', ['%player%' => $player->nickname]);

            return;
        }

        $state->data['hasDrawn'] = false;
        $state->data['turnMelds'] = [];
        $state->data['turnMeldPoints'] = 0;
        $state->advanceTurn();
    }

    private function takeback(GameState $state): void
    {
        if ([] === $state->data['turnMelds']) {
            $this->invalidMove('error.rummy.nothing_to_takeback');
        }

        $player = $state->currentPlayer();
        $hand = $state->table->hand($player->id);

        foreach ($state->data['turnMelds'] as $meldKey) {
            $hand->push(...$state->table->zone($meldKey)->clear());
            $state->table->remove($meldKey);
        }
        $state->data['turnMelds'] = [];
        $state->data['turnMeldPoints'] = 0;

        $state->logGameEvent('log.rummy.takeback', ['%player%' => $player->nickname]);
    }

    private function assertNotDrawn(GameState $state): void
    {
        if ($state->data['hasDrawn']) {
            $this->invalidMove('error.rummy.already_drawn');
        }
    }

    private function assertDrawn(GameState $state): void
    {
        if (!$state->data['hasDrawn']) {
            $this->invalidMove('error.rummy.draw_first');
        }
    }

    /**
     * @param list<PlayingCard> $hand
     * @param list<int> $indexes
     *
     * @return list<PlayingCard>
     */
    private function pick(array $hand, array $indexes): array
    {
        $cards = [];
        foreach ($indexes as $index) {
            if (!isset($hand[$index])) {
                $this->invalidMove('error.rummy.unknown_card');
            }
            $cards[] = $hand[$index];
        }

        return $cards;
    }

    /**
     * @param list<PlayingCard> $hand
     * @param list<int> $indexes
     */
    private function remove(array &$hand, array $indexes): void
    {
        rsort($indexes);
        foreach ($indexes as $index) {
            array_splice($hand, $index, 1);
        }
    }
}
