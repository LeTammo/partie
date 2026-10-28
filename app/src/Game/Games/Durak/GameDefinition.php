<?php

declare(strict_types=1);

namespace App\Game\Games\Durak;

use App\Game\Core\Card\DeckFactory;
use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Service\AbstractGameDefinition;
use App\Game\Core\Zone\Table;
use App\Game\Core\Zone\Zone;
use App\Game\Core\Zone\ZoneVisibility;

// Durak as a free table simulation: the engine only tracks where each card
// lies and who may physically reach it - it never enforces Durak's rules.
// Attacking, defending, cheating and calling cheats out is negotiated by
// the players themselves, exactly like at a real table.
// Every card carries an opaque uid so it can travel between zones with a
// visible glide for all viewers without ever leaking its face.
final readonly class GameDefinition extends AbstractGameDefinition
{
    public function __construct(
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
        return 'games/durak';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 6;
    }

    public function hasTurns(): bool
    {
        return false;
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;
        $state->data['offers'] = [];
        $table = $state->table = new Table();

        $deck = array_map(
            static fn (PlayingCard $card): array => ['uid' => self::newUid(), 'card' => $card],
            DeckFactory::deck36(),
        );
        // the bottom card of the stock shows the trump suit, like on a real table
        $trumpSuit = $deck[0]['card']->suit;
        $state->data['trumpSuit'] = $trumpSuit->value;

        $table->add(new Zone('stock', visibility: ZoneVisibility::Hidden))->push(...$deck);
        $table->add(new Zone('discard', visibility: ZoneVisibility::Hidden));
        $table->add(new Zone('middle'));
        foreach ($players as $player) {
            $table->add(new Zone('hand:'.$player->id, $player->id, ZoneVisibility::Owner));
        }

        $state->logGameEvent('log.durak.started', ['%suit%' => $trumpSuit->symbol()]);

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        match ($payload['action'] ?? '') {
            'move' => $this->moveCard($state, $playerId, $this->stringParam($payload, 'from'), $this->stringParam($payload, 'to')),
            'flip' => $this->flip($state, $playerId, $this->stringParam($payload, 'uid')),
            'accept_offer' => $this->acceptOffer($state, $playerId, $this->stringParam($payload, 'from')),
            'decline_offer' => $this->declineOffer($state, $playerId, $this->stringParam($payload, 'from')),
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

    private function moveCard(GameState $state, string $playerId, string $from, string $to): void
    {
        // drawing from a hidden pile ("stock:<uid>"/"discard:<uid>") never
        // fails just because someone else was faster - the specific card was
        // never chosen on purpose (it's face down, drawing happens by feel),
        // so whichever one is now on top is exactly as good. This has to hold
        // even when many players draw in the same instant.
        foreach (['stock', 'discard'] as $pileKey) {
            if (str_starts_with($from, $pileKey.':')) {
                $this->drawFromPile($state, $playerId, $pileKey, $to);

                return;
            }
        }

        if (!str_starts_with($from, 'card:')) {
            $this->invalidMove('error.durak.bad_move');
        }
        $uid = substr($from, 5);

        [$sourceKey, $index] = $this->locateReachable($state, $playerId, $uid)
            ?? $this->invalidMove('error.durak.card_moved');

        // sliding a card into another hand only makes an offer - the card
        // stays put until its receiver accepts
        if (str_starts_with($to, 'hand:') && 'hand:'.$playerId !== $to) {
            $this->offer($state, $playerId, $uid, $sourceKey, substr($to, 5));

            return;
        }

        // "stack:<uid>" stacks onto a specific middle card instead of the
        // open middle in general - the classic "I defend this attack" gesture.
        // Only the current top of a pile may be freshly targeted - each card
        // ever gets at most one card stacked directly on it, though a chain
        // can grow arbitrarily deep by stacking onto the new top in turn.
        // Also works the other way around: a card already lying in the open
        // middle can join another pile, or a card already on top of a pile
        // can be pulled back down to lie by itself again.
        $stackOn = null;
        if (str_starts_with($to, 'stack:')) {
            $stackOn = substr($to, 6);
            if (null === $this->indexOf($state->table->zone('middle'), $stackOn)
                || $this->hasStackedChild($state, $stackOn)
                || $this->isInOwnChain($state, $uid, $stackOn)) {
                $this->invalidMove('error.durak.card_moved');
            }
            $to = 'middle';
        }

        $allowed = match ($sourceKey) {
            'hand:'.$playerId => ['middle', 'discard', 'stock'],
            'middle' => ['hand:'.$playerId, 'discard', 'middle'],
            default => [],
        };
        if (!\in_array($to, $allowed, true)) {
            $this->invalidMove('error.durak.bad_move');
        }

        $item = $state->table->zone($sourceKey)->removeAt($index);
        $wasFaceUp = $item['faceUp'] ?? false;
        unset($item['faceUp'], $item['stackOn']);
        if ('middle' === $to) {
            // a freshly played hand card always lands face up; a card
            // already in the middle keeps whatever face it was showing
            $item['faceUp'] = 'hand:'.$playerId === $sourceKey || $wasFaceUp;
            $item['stackOn'] = $stackOn;
        }
        $state->table->zone($to)->push($item);

        // taking a card out of the middle carries whatever is stacked on it
        // along, in the same order - you can always take the top of a pile,
        // but disturbing one underneath brings the whole pile above it too
        if ('middle' === $sourceKey && 'middle' !== $to) {
            $this->moveStackedChain($state, $uid, $to);
        }

        // a moved card can no longer be on offer
        if (($state->data['offers'][$playerId]['uid'] ?? null) === $uid) {
            unset($state->data['offers'][$playerId]);
        }

        if (null !== $stackOn) {
            $this->logCardEvent($state, 'log.durak.stacked', $playerId, $item['card']);
        } else {
            $this->logMove($state, $playerId, $sourceKey, $to, $item['card'], $wasFaceUp);
        }
    }

    /**
     * Draws whatever is currently on top of $pileKey into the player's hand,
     * ignoring which card the client asked for - a stale optimistic guess
     * (someone else drew first) still succeeds, it just hands over a
     * different card. Only fails once the pile is truly empty.
     */
    private function drawFromPile(GameState $state, string $playerId, string $pileKey, string $to): void
    {
        if ('hand:'.$playerId !== $to) {
            $this->invalidMove('error.durak.bad_move');
        }

        $pile = $state->table->zone($pileKey);
        if ($pile->isEmpty()) {
            $this->invalidMove('error.durak.pile_empty');
        }

        $item = $pile->pop();
        unset($item['faceUp'], $item['stackOn']);
        $state->table->hand($playerId)->push($item);

        $state->logGameEvent(
            'stock' === $pileKey ? 'log.durak.drew' : 'log.durak.took_discard',
            ['%player%' => $state->playerById($playerId)->nickname],
        );
    }

    private function flip(GameState $state, string $playerId, string $uid): void
    {
        $middle = $state->table->zone('middle');
        foreach ($middle->items as $i => $item) {
            if ($item['uid'] === $uid) {
                $faceUp = !$item['faceUp'];
                $middle->items[$i]['faceUp'] = $faceUp;
                $this->logCardEvent($state, $faceUp ? 'log.durak.flipped_up' : 'log.durak.flipped_down', $playerId, $item['card']);

                return;
            }
        }

        $this->invalidMove('error.durak.card_moved');
    }

    private function offer(GameState $state, string $playerId, string $uid, string $sourceKey, string $toPlayerId): void
    {
        if ('hand:'.$playerId !== $sourceKey || null === $state->playerById($toPlayerId)) {
            $this->invalidMove('error.durak.bad_move');
        }

        // deliberately unlogged - slipping a card to someone is meant to be sneaky
        $state->data['offers'][$playerId] = ['to' => $toPlayerId, 'uid' => $uid];
    }

    private function acceptOffer(GameState $state, string $playerId, string $fromPlayerId): void
    {
        $offer = $state->data['offers'][$fromPlayerId] ?? $this->invalidMove('error.durak.no_offer');
        if ($playerId !== $offer['to']) {
            $this->invalidMove('error.durak.no_offer');
        }

        $fromHand = $state->table->hand($fromPlayerId);
        $index = $this->indexOf($fromHand, $offer['uid']);
        unset($state->data['offers'][$fromPlayerId]);
        if (null === $index) {
            $this->invalidMove('error.durak.offer_gone');
        }

        $item = $fromHand->removeAt($index);
        // a fresh uid breaks the FLIP identity: the card quietly disappears
        // from one hand and appears in the other instead of visibly flying
        $item['uid'] = self::newUid();
        $state->table->hand($playerId)->push($item);
        // deliberately unlogged, see offer()
    }

    private function declineOffer(GameState $state, string $playerId, string $fromPlayerId): void
    {
        $offer = $state->data['offers'][$fromPlayerId] ?? $this->invalidMove('error.durak.no_offer');
        if ($playerId !== $offer['to'] && $playerId !== $fromPlayerId) {
            $this->invalidMove('error.durak.no_offer');
        }

        unset($state->data['offers'][$fromPlayerId]);
    }

    /**
     * Where the card lies, if the player may physically reach it: the own
     * hand or the open middle. Stock/discard draws don't go through here -
     * see drawFromPile().
     *
     * @return array{string, int}|null
     */
    private function locateReachable(GameState $state, string $playerId, string $uid): ?array
    {
        $table = $state->table;

        if (null !== ($index = $this->indexOf($table->hand($playerId), $uid))) {
            return ['hand:'.$playerId, $index];
        }
        if (null !== ($index = $this->indexOf($table->zone('middle'), $uid))) {
            return ['middle', $index];
        }

        return null;
    }

    /**
     * Moves whatever is stacked directly on $uid (and, recursively,
     * whatever is stacked on that) to $to as well - a disturbed pile
     * travels together.
     */
    private function moveStackedChain(GameState $state, string $uid, string $to): void
    {
        $middle = $state->table->zone('middle');
        foreach ($middle->items as $i => $child) {
            if (($child['stackOn'] ?? null) === $uid) {
                $childItem = $middle->removeAt($i);
                $childUid = $childItem['uid'];
                unset($childItem['stackOn']);
                $state->table->zone($to)->push($childItem);
                $this->moveStackedChain($state, $childUid, $to);

                return; // at most one card is ever stacked directly on another
            }
        }
    }

    private function hasStackedChild(GameState $state, string $uid): bool
    {
        foreach ($state->table->zone('middle')->items as $item) {
            if (($item['stackOn'] ?? null) === $uid) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when $targetUid is $movingUid itself, or anywhere in the pile
     * stacked on top of it - stacking $movingUid there would either be a
     * no-op or close a cycle (a card resting on its own descendant).
     */
    private function isInOwnChain(GameState $state, string $movingUid, string $targetUid): bool
    {
        if ($movingUid === $targetUid) {
            return true;
        }

        foreach ($state->table->zone('middle')->items as $item) {
            if (($item['stackOn'] ?? null) === $movingUid && $this->isInOwnChain($state, $item['uid'], $targetUid)) {
                return true;
            }
        }

        return false;
    }

    private function indexOf(Zone $zone, string $uid): ?int
    {
        foreach ($zone->items as $index => $item) {
            if ($item['uid'] === $uid) {
                return $index;
            }
        }

        return null;
    }

    private function logMove(GameState $state, string $playerId, string $from, string $to, PlayingCard $card, bool $wasFaceUp): void
    {
        $hand = 'hand:'.$playerId;
        [$key, $named] = match (true) {
            $from === $hand && 'stock' === $to => ['log.durak.returned_stock', false],
            $from === $hand && 'discard' === $to => ['log.durak.discarded', false],
            $from === $hand && 'middle' === $to => ['log.durak.played', true],
            'middle' === $from && $to === $hand => $wasFaceUp
                ? ['log.durak.took_middle', true]
                : ['log.durak.took_middle_hidden', false],
            'middle' === $from && 'discard' === $to => $wasFaceUp
                ? ['log.durak.cleared', true]
                : ['log.durak.cleared_hidden', false],
            'middle' === $from && 'middle' === $to => ['log.durak.repositioned', false],
        };

        if ($named) {
            $this->logCardEvent($state, $key, $playerId, $card);
        } else {
            $state->logGameEvent($key, ['%player%' => $state->playerById($playerId)->nickname]);
        }
    }

    private function logCardEvent(GameState $state, string $key, string $playerId, PlayingCard $card): void
    {
        $state->logGameEvent($key, [
            '%player%' => $state->playerById($playerId)->nickname,
            '%suit%' => $card->suit->symbol(),
            '%rank%' => 't:card.rank.'.$card->rank->labelKey(),
        ]);
    }

    private static function newUid(): string
    {
        return bin2hex(random_bytes(4));
    }
}
