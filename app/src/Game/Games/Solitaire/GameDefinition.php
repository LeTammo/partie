<?php

declare(strict_types=1);

namespace App\Game\Games\Solitaire;

use App\Game\Core\Card\DeckFactory;
use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Suit;
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
    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'solitaire';
    }

    public function getName(): string
    {
        return 'game.solitaire.name';
    }

    public function getDescription(): string
    {
        return 'game.solitaire.description';
    }

    public function getIcon(): string
    {
        return 'solitaire';
    }

    public function getMinPlayers(): int
    {
        return 1;
    }

    public function getMaxPlayers(): int
    {
        return 1;
    }

    public function settings(): array
    {
        return [
            new GameSetting(
                key: 'drawCount',
                labelKey: 'setting.solitaire.draw_count',
                type: GameSettingType::Enum,
                default: '1',
                options: ['1' => 'setting.solitaire.draw_one', '3' => 'setting.solitaire.draw_three'],
            ),
        ];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;
        $table = $state->table = new Table();

        $deck = DeckFactory::deck52();

        for ($col = 0; $col < GameRules::COLUMNS; ++$col) {
            $zone = $table->add(new Zone("tableau:$col"));
            for ($i = 0; $i <= $col; ++$i) {
                $zone->push(['card' => array_pop($deck), 'faceUp' => $i === $col]);
            }
        }

        foreach (Suit::cases() as $suit) {
            $table->add(new Zone('foundation:'.$suit->value));
        }

        $table->add(new Zone('stock', visibility: ZoneVisibility::Hidden))->push(...$deck);
        $table->add(new Zone('waste'));

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'draw' => $this->draw($state),
            'move' => $this->move($state, $this->stringParam($payload, 'from'), $this->stringParam($payload, 'to')),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/solitaire/board.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function draw(GameState $state): void
    {
        $player = $state->currentPlayer();
        $stock = $state->table->zone('stock');
        $waste = $state->table->zone('waste');

        if ($stock->isEmpty()) {
            if ($waste->isEmpty()) {
                $this->invalidMove('error.solitaire.nothing_to_draw');
            }

            $stock->items = array_reverse($waste->clear());
            $state->logGameEvent('log.solitaire.recycled', ['%player%' => $player->nickname]);

            return;
        }

        $count = (int) ($this->setting($state, 'drawCount') ?? 1);
        for ($i = 0; $i < $count && !$stock->isEmpty(); ++$i) {
            $waste->push($stock->pop());
        }
        $state->logGameEvent('log.solitaire.drew', ['%player%' => $player->nickname]);
    }

    private function move(GameState $state, string $from, string $to): void
    {
        $fromCol = $this->columnOf($from);
        if (null !== $fromCol && $to === 'tableau:'.$fromCol) {
            $this->invalidMove('error.solitaire.invalid_move');
        }

        $cards = $this->cardsAt($state, $from);
        if (null === $cards) {
            $this->invalidMove('error.solitaire.invalid_move');
        }

        if (\count($cards) > 1 && str_starts_with($to, 'foundation:')) {
            $this->invalidMove('error.solitaire.invalid_move');
        }

        if (!$this->canPlace($state, $to, $cards[0])) {
            $this->invalidMove('error.solitaire.invalid_move');
        }

        $this->removeFrom($state, $from, \count($cards));
        $this->appendTo($state, $to, $cards);
        $this->flipExposedCard($state, $from);

        $player = $state->currentPlayer();
        $state->logGameEvent('log.solitaire.moved', ['%player%' => $player->nickname]);

        if ($this->isWon($state)) {
            $state->finish($player->id);
            $state->logEvent('log.won', ['%player%' => $player->nickname]);
        }
    }

    private function columnOf(string $location): ?int
    {
        return preg_match('/^tableau:(\d+)/', $location, $m) ? (int) $m[1] : null;
    }

    /**
     * @return list<PlayingCard>|null
     */
    private function cardsAt(GameState $state, string $from): ?array
    {
        if ('waste' === $from) {
            $top = $state->table->zone('waste')->top();

            return null !== $top ? [$top] : null;
        }

        if (!preg_match('/^tableau:(\d+):(\d+)$/', $from, $m)) {
            return null;
        }

        $zoneKey = 'tableau:'.$m[1];
        if (!$state->table->has($zoneKey)) {
            return null;
        }

        $pile = $state->table->zone($zoneKey)->items;
        $index = (int) $m[2];
        if (!isset($pile[$index]) || !$pile[$index]['faceUp']) {
            return null;
        }

        return array_map(static fn (array $slot): PlayingCard => $slot['card'], \array_slice($pile, $index));
    }

    private function canPlace(GameState $state, string $to, PlayingCard $bottomOfRun): bool
    {
        if (preg_match('/^tableau:(\d+)$/', $to, $m)) {
            if (!$state->table->has($to)) {
                return false;
            }

            return $this->rules->canDropOnTableau($bottomOfRun, $state->table->zone($to)->items);
        }

        if (preg_match('/^foundation:(\w+)$/', $to, $m)) {
            $suit = Suit::tryFrom($m[1]);
            if (null === $suit || $suit !== $bottomOfRun->suit) {
                return false;
            }

            return $this->rules->canDropOnFoundation($bottomOfRun, $state->table->zone($to)->items);
        }

        return false;
    }

    private function removeFrom(GameState $state, string $from, int $count): void
    {
        if ('waste' === $from) {
            $state->table->zone('waste')->pop();

            return;
        }

        $zone = $state->table->zone('tableau:'.$this->columnOf($from));
        array_splice($zone->items, -$count);
    }

    /**
     * @param list<PlayingCard> $cards
     */
    private function appendTo(GameState $state, string $to, array $cards): void
    {
        if (str_starts_with($to, 'foundation:')) {
            $state->table->zone($to)->push(...$cards);

            return;
        }

        $zone = $state->table->zone('tableau:'.$this->columnOf($to));
        foreach ($cards as $card) {
            $zone->push(['card' => $card, 'faceUp' => true]);
        }
    }

    private function flipExposedCard(GameState $state, string $from): void
    {
        $col = $this->columnOf($from);
        if (null === $col) {
            return;
        }

        $zone = $state->table->zone("tableau:$col");
        if (!$zone->isEmpty()) {
            $zone->items[array_key_last($zone->items)]['faceUp'] = true;
        }
    }

    private function isWon(GameState $state): bool
    {
        foreach ($state->table->matching('foundation:') as $foundation) {
            if (13 !== $foundation->count()) {
                return false;
            }
        }

        return true;
    }
}
