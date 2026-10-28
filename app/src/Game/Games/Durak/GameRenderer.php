<?php

declare(strict_types=1);

namespace App\Game\Games\Durak;

use App\Game\Core\Card\CardPresenter;
use App\Game\Core\Card\Suit;
use App\Game\Core\Model\GameState;
use App\Game\Core\View\MoveMap;
use App\Game\Core\Zone\Zone;

final readonly class GameRenderer
{
    // seats around the table, in ring order starting left of the viewer:
    // up the left edge, across the top, down the right edge
    private const array SEAT_RINGS = [
        1 => ['top'],
        2 => ['left', 'right'],
        3 => ['left', 'top', 'right'],
        4 => ['left', 'left', 'right', 'right'],
        5 => ['left', 'left', 'top', 'right', 'right'],
        6 => ['left', 'left', 'top', 'top', 'right', 'right'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $table = $state->table;
        $me = null !== $viewerId && null !== $state->playerById($viewerId) ? $viewerId : null;

        $middleZone = $table->zone('middle');
        $trumpSuit = Suit::from($state->data['trumpSuit']);

        $stock = $this->pileView($table->zone('stock'), trumpPeek: true);
        $discard = $this->pileView($table->zone('discard'), trumpPeek: false);
        $middle = $this->middleGroups($middleZone);

        $hand = [];
        $outgoingOffer = null !== $me ? ($state->data['offers'][$me] ?? null) : null;
        if (null !== $me) {
            foreach ($table->hand($me)->items as $item) {
                $hand[] = [
                    'uid' => $item['uid'],
                    'card' => CardPresenter::view($item['card']),
                    'offeredTo' => ($outgoingOffer['uid'] ?? null) === $item['uid']
                        ? $state->playerById($outgoingOffer['to'])->nickname
                        : null,
                ];
            }
        }

        $incomingOffers = [];
        if (null !== $me) {
            foreach ($state->data['offers'] as $fromId => $offer) {
                if ($offer['to'] !== $me) {
                    continue;
                }
                $index = $this->indexOf($table->hand($fromId), $offer['uid']);
                if (null !== $index) {
                    $incomingOffers[] = [
                        'fromId' => $fromId,
                        'fromNickname' => $state->playerById($fromId)->nickname,
                        'card' => CardPresenter::view($table->hand($fromId)->items[$index]['card']),
                    ];
                }
            }
        }

        return [
            'isPlayer' => null !== $me,
            'seats' => $this->seats($state, $me),
            'middle' => $middle,
            'hand' => $hand,
            'stock' => $stock,
            'discard' => $discard,
            'trumpSuit' => $trumpSuit->symbol(),
            'trumpRed' => $trumpSuit->isRed(),
            'moves' => $this->moves($state, $me, $stock['reachableUids'], $discard['reachableUids'], $middle['leafUids'], $middle['rootOf'])->toArray(),
            'incomingOffers' => $incomingOffers,
        ];
    }

    /**
     * A hidden pile as a stack of individually rendered card layers - every
     * card that could conceivably become reachable is a real element from
     * the start (only the visually topmost one is ever actually clickable,
     * since the rest sit fully overlapped behind it), so drawing the next
     * card never waits on a server round trip.
     *
     * @return array{count: int, layers: list<array<string, mixed>>, trumpPeek: ?array<string, mixed>, reachableUids: list<string>}
     */
    private function pileView(Zone $pile, bool $trumpPeek): array
    {
        $items = $pile->items;
        $count = \count($items);

        // the bottom card of the stock is shown separately, tucked out to the
        // side, until it is the only card left - it never becomes a layer
        // itself while other cards still sit on top of it
        $peek = $trumpPeek && $count > 1 ? CardPresenter::view($items[0]['card']) : null;
        $layerItems = $trumpPeek && $count > 1 ? \array_slice($items, 1) : $items;

        $layers = [];
        foreach ($layerItems as $item) {
            $faceUp = $trumpPeek && 1 === $count;
            $layers[] = ($faceUp ? CardPresenter::view($item['card']) : ['back' => true])
                + ['uid' => $item['uid']];
        }

        return [
            'count' => $count,
            'layers' => $layers,
            'trumpPeek' => $peek,
            'reachableUids' => array_column($layers, 'uid'),
        ];
    }

    /**
     * Builds the open middle as chains: each card may have at most one card
     * stacked directly on it (the "I defend this attack" gesture), but that
     * card can in turn carry another, growing an arbitrarily deep pile where
     * every card stays visible, offset a little further each level. Each
     * chain is a flat, root-to-leaf ordered list - not a nested tree - so
     * templates can render every card as a direct sibling of its pile-mates
     * and wire `dragdrop--piece-move`'s `runs` mode: picking any card in the
     * list also picks whatever follows it, carrying the pile above it along.
     * Only the current top of a chain (nothing stacked on it yet) may be
     * freshly targeted - see GameDefinition::moveStackedChain().
     *
     * @return array{chains: list<list<array<string, mixed>>>, leafUids: list<string>, rootOf: array<string, string>}
     */
    private function middleGroups(Zone $middle): array
    {
        $byUid = [];
        foreach ($middle->items as $item) {
            $byUid[$item['uid']] = $item;
        }

        $childOf = [];
        foreach ($middle->items as $item) {
            $stackOn = $item['stackOn'] ?? null;
            if (null !== $stackOn && isset($byUid[$stackOn])) {
                $childOf[$stackOn] = $item;
            }
        }

        $buildChain = function (array $item) use (&$buildChain, $childOf): array {
            $cardView = [
                'uid' => $item['uid'],
                'faceUp' => $item['faceUp'],
                'card' => $item['faceUp'] ? CardPresenter::view($item['card']) : null,
                'isLeaf' => !isset($childOf[$item['uid']]),
            ];
            $child = $childOf[$item['uid']] ?? null;

            return null !== $child ? [$cardView, ...$buildChain($child)] : [$cardView];
        };

        $chains = [];
        $rootOf = [];
        foreach ($middle->items as $item) {
            $stackOn = $item['stackOn'] ?? null;
            if (null !== $stackOn && isset($byUid[$stackOn])) {
                continue; // part of another card's chain, not a root of its own
            }
            $chain = $buildChain($item);
            $chains[] = $chain;
            foreach ($chain as $cardView) {
                $rootOf[$cardView['uid']] = $item['uid'];
            }
        }

        $leafUids = [];
        foreach ($middle->items as $item) {
            if (!isset($childOf[$item['uid']])) {
                $leafUids[] = $item['uid'];
            }
        }

        return ['chains' => $chains, 'leafUids' => $leafUids, 'rootOf' => $rootOf];
    }

    /**
     * The other players in ring order around the table, each with the uids
     * of their face-down cards.
     *
     * @return array{left: list<array<string, mixed>>, top: list<array<string, mixed>>, right: list<array<string, mixed>>}
     */
    private function seats(GameState $state, ?string $me): array
    {
        $players = $state->players;
        $offset = 0;
        foreach ($players as $i => $player) {
            if ($player->id === $me) {
                $offset = $i + 1;
                break;
            }
        }

        $others = [];
        $count = \count($players);
        for ($i = 0; $i < $count; ++$i) {
            $player = $players[($offset + $i) % $count];
            if ($player->id !== $me) {
                $others[] = $player;
            }
        }

        $ring = self::SEAT_RINGS[\count($others)];
        $seats = ['left' => [], 'top' => [], 'right' => []];
        foreach ($others as $i => $player) {
            $seats[$ring[$i]][] = [
                'id' => $player->id,
                'nickname' => $player->nickname,
                'color' => $player->color,
                'current' => false,
                'cardUids' => array_column($state->table->hand($player->id)->items, 'uid'),
            ];
        }
        // the left edge fills bottom-up in ring order, but reads top-down
        $seats['left'] = array_reverse($seats['left']);

        return $seats;
    }

    /**
     * @param list<string> $stockUids
     * @param list<string> $discardUids
     * @param list<string> $middleLeafUids
     * @param array<string, string> $middleRootOf
     */
    private function moves(GameState $state, ?string $me, array $stockUids, array $discardUids, array $middleLeafUids, array $middleRootOf): MoveMap
    {
        $moves = new MoveMap();
        if (null === $me) {
            return $moves;
        }

        $table = $state->table;
        $myHand = 'hand:'.$me;
        $otherHands = [];
        foreach ($state->players as $player) {
            if ($player->id !== $me) {
                $otherHands[] = 'hand:'.$player->id;
            }
        }
        $stackDestinations = array_map(static fn (string $uid): string => 'stack:'.$uid, $middleLeafUids);

        foreach ($table->hand($me)->items as $item) {
            $moves->add('card:'.$item['uid'], 'middle', 'discard', 'stock', ...$otherHands, ...$stackDestinations);
        }
        foreach ($table->zone('middle')->items as $item) {
            $uid = $item['uid'];
            $ownRoot = $middleRootOf[$uid] ?? $uid;
            // a card already in the middle may join another pile too, just
            // never its own (stacking onto your own chain would be a no-op
            // at best and a cycle at worst)
            $ownStackDestinations = [];
            foreach ($middleLeafUids as $leafUid) {
                if (($middleRootOf[$leafUid] ?? $leafUid) !== $ownRoot) {
                    $ownStackDestinations[] = 'stack:'.$leafUid;
                }
            }
            $moves->add('card:'.$uid, $myHand, 'discard', 'middle', ...$ownStackDestinations);
        }
        // sourced as "stock:<uid>"/"discard:<uid>", not "card:<uid>" - drawing
        // from a hidden pile always succeeds and just gives you the current
        // top, so the specific uid here is only an optimistic-render hint
        foreach ($stockUids as $uid) {
            $moves->add('stock:'.$uid, $myHand);
        }
        foreach ($discardUids as $uid) {
            $moves->add('discard:'.$uid, $myHand);
        }

        return $moves;
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
}
