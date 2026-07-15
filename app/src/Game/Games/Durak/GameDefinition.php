<?php

declare(strict_types=1);

namespace App\Game\Games\Durak;

use App\Game\Core\Card\DeckFactory;
use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Suit;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Model\GameStatus;
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
        return 'durak';
    }

    public function getName(): string
    {
        return 'game.durak.name';
    }

    public function getDescription(): string
    {
        return 'game.durak.description';
    }

    public function getIcon(): string
    {
        return 'durak';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 2;
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;
        $table = $state->table = new Table();

        $deck = DeckFactory::deck36();
        foreach ($players as $player) {
            $table->add(new Zone('hand:'.$player->id, $player->id, ZoneVisibility::Owner))
                ->push(...array_splice($deck, -GameRules::HAND_SIZE));
        }
        // deck[0] (the very first shuffled card) is dealt last - the trump card
        $trump = $deck[0];
        $table->add(new Zone('stock', visibility: ZoneVisibility::Hidden))->push(...$deck);
        $table->add(new Zone('discard', visibility: ZoneVisibility::Hidden));
        $table->add(new Zone('attack'));

        $state->data['trumpSuit'] = $trump->suit->value;
        $state->data['attackerId'] = $players[0]->id;
        $state->data['defenderId'] = $players[1]->id;
        $state->currentTurnIndex = $players[0]->seat;

        $state->logGameEvent('log.durak.started', ['%suit%' => $trump->suit->symbol()]);

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'attack' => $this->attack($state, $playerId, $this->intParam($payload, 'card')),
            'defend' => $this->defend($state, $playerId, $this->intParam($payload, 'pair'), $this->intParam($payload, 'card')),
            'take' => $this->take($state, $playerId),
            'done' => $this->done($state, $playerId),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/durak/table.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function attack(GameState $state, string $playerId, int $cardIndex): void
    {
        if ($playerId !== $state->data['attackerId']) {
            $this->invalidMove('error.durak.not_attacker');
        }

        $hand = $state->table->hand($playerId);
        $defenderHand = $state->table->hand($state->data['defenderId']);
        $attackZone = $state->table->zone('attack');

        if (!isset($hand->items[$cardIndex])) {
            $this->invalidMove('error.durak.unknown_card');
        }
        if (\count($attackZone->items) >= min(GameRules::MAX_ATTACK_CARDS, $defenderHand->count())) {
            $this->invalidMove('error.durak.attack_limit');
        }

        /** @var PlayingCard $card */
        $card = $hand->items[$cardIndex];
        if (!$this->rules->canAttackWith($card, $attackZone->items)) {
            $this->invalidMove('error.durak.rank_not_on_table');
        }

        $hand->removeAt($cardIndex);
        $attackZone->push(['attack' => $card, 'defend' => null]);

        $state->logGameEvent('log.durak.attacked', [
            '%player%' => $state->currentPlayer()->nickname,
            '%suit%' => $card->suit->symbol(),
            '%rank%' => 't:card.rank.'.$card->rank->labelKey(),
        ]);

        $this->setTurn($state, $state->data['defenderId']);
    }

    private function defend(GameState $state, string $playerId, int $pairIndex, int $cardIndex): void
    {
        if ($playerId !== $state->data['defenderId']) {
            $this->invalidMove('error.durak.not_defender');
        }

        $attackZone = $state->table->zone('attack');
        $hand = $state->table->hand($playerId);

        if (!isset($attackZone->items[$pairIndex]) || null !== $attackZone->items[$pairIndex]['defend']) {
            $this->invalidMove('error.durak.unknown_pair');
        }
        if (!isset($hand->items[$cardIndex])) {
            $this->invalidMove('error.durak.unknown_card');
        }

        /** @var PlayingCard $card */
        $card = $hand->items[$cardIndex];
        $attackCard = $attackZone->items[$pairIndex]['attack'];
        $trumpSuit = Suit::from($state->data['trumpSuit']);

        if (!$this->rules->beats($card, $attackCard, $trumpSuit)) {
            $this->invalidMove('error.durak.cannot_beat');
        }

        $hand->removeAt($cardIndex);
        $attackZone->items[$pairIndex]['defend'] = $card;

        $state->logGameEvent('log.durak.defended', ['%player%' => $state->currentPlayer()->nickname]);

        $this->setTurn($state, $state->data['attackerId']);
    }

    private function take(GameState $state, string $playerId): void
    {
        if ($playerId !== $state->data['defenderId']) {
            $this->invalidMove('error.durak.not_defender');
        }

        $attackZone = $state->table->zone('attack');
        if ([] === $attackZone->items) {
            $this->invalidMove('error.durak.nothing_to_take');
        }

        $hand = $state->table->hand($playerId);
        foreach ($attackZone->clear() as $pair) {
            $hand->push($pair['attack']);
            if (null !== $pair['defend']) {
                $hand->push($pair['defend']);
            }
        }

        $state->logGameEvent('log.durak.took', ['%player%' => $state->currentPlayer()->nickname]);

        $this->refillHands($state, $state->data['attackerId']);
        $this->setTurn($state, $state->data['attackerId']);
        $this->checkGameEnd($state);
    }

    private function done(GameState $state, string $playerId): void
    {
        if ($playerId !== $state->data['attackerId']) {
            $this->invalidMove('error.durak.not_attacker');
        }

        $attackZone = $state->table->zone('attack');
        if (!$this->rules->allDefended($attackZone->items)) {
            $this->invalidMove('error.durak.not_all_defended');
        }

        foreach ($attackZone->clear() as $pair) {
            $state->table->zone('discard')->push($pair['attack'], $pair['defend']);
        }

        $state->logGameEvent('log.durak.beaten', ['%player%' => $state->playerById($state->data['defenderId'])->nickname]);

        // roles swap: the defender becomes the new attacker
        $newAttacker = $state->data['defenderId'];
        $newDefender = $state->data['attackerId'];
        $state->data['attackerId'] = $newAttacker;
        $state->data['defenderId'] = $newDefender;

        $this->refillHands($state, $newAttacker);
        $this->setTurn($state, $newAttacker);
        $this->checkGameEnd($state);
    }

    private function refillHands(GameState $state, string $firstPlayerId): void
    {
        $order = [$firstPlayerId, $this->otherPlayer($state, $firstPlayerId)];
        foreach ($order as $playerId) {
            $hand = $state->table->hand($playerId);
            $stock = $state->table->zone('stock');
            while ($hand->count() < GameRules::HAND_SIZE && !$stock->isEmpty()) {
                $hand->push($stock->pop());
            }
        }
    }

    private function checkGameEnd(GameState $state): void
    {
        if (!$state->table->zone('stock')->isEmpty()) {
            return;
        }

        $attackerEmpty = $state->table->hand($state->data['attackerId'])->isEmpty();
        $defenderEmpty = $state->table->hand($state->data['defenderId'])->isEmpty();

        if ($attackerEmpty && $defenderEmpty) {
            $state->finish(null);
            $state->logEvent('log.draw_full');
        } elseif ($attackerEmpty) {
            $state->finish($state->data['attackerId']);
            $state->logEvent('log.won', ['%player%' => $state->playerById($state->data['attackerId'])->nickname]);
        } elseif ($defenderEmpty) {
            $state->finish($state->data['defenderId']);
            $state->logEvent('log.won', ['%player%' => $state->playerById($state->data['defenderId'])->nickname]);
        }
    }

    private function setTurn(GameState $state, string $playerId): void
    {
        if (GameStatus::Running === $state->status) {
            $state->currentTurnIndex = $state->playerById($playerId)->seat;
        }
    }

    private function otherPlayer(GameState $state, string $playerId): string
    {
        return $state->players[0]->id === $playerId ? $state->players[1]->id : $state->players[0]->id;
    }
}
