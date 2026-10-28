<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Games\Durak\GameDefinition;
use App\Game\Games\Durak\GameRenderer;

final class DurakTest extends GameTestCase
{
    private GameDefinition $game;

    protected function setUp(): void
    {
        $this->game = new GameDefinition(new GameRenderer());
    }

    private function state(int $players = 2): GameState
    {
        return $this->game->createInitialState(self::players($players));
    }

    private static function uidOf(GameState $state, string $zoneKey, int $index = -1): string
    {
        $items = $state->table->zone($zoneKey)->items;
        $index = $index >= 0 ? $index : \count($items) + $index;

        return $items[$index]['uid'];
    }

    public function testInitialStateHasFullStockAndEmptyHands(): void
    {
        $state = $this->state();

        self::assertCount(36, $state->table->zone('stock')->items);
        self::assertTrue($state->table->zone('middle')->isEmpty());
        self::assertTrue($state->table->zone('discard')->isEmpty());
        self::assertTrue($state->table->hand('p0')->isEmpty());
        self::assertTrue($state->table->hand('p1')->isEmpty());
        // the bottom stock card determines the trump suit
        self::assertSame($state->table->zone('stock')->items[0]['card']->suit->value, $state->data['trumpSuit']);
        self::assertFalse($this->game->hasTurns());
    }

    public function testAnyPlayerMayDrawReturnPlayAndDiscardAtAnyTime(): void
    {
        $state = $this->state();

        // both players draw without any turn order
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p1']);
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        self::assertCount(1, $state->table->hand('p0')->items);
        self::assertCount(1, $state->table->hand('p1')->items);

        // play into the middle - lands face up
        $uid = self::uidOf($state, 'hand:p0');
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$uid, 'to' => 'middle']);
        self::assertTrue($state->table->zone('middle')->top()['faceUp']);

        // anyone may take it back, even the other player
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'card:'.$uid, 'to' => 'hand:p1']);
        self::assertCount(2, $state->table->hand('p1')->items);

        // discard and take back from the discard pile
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'card:'.$uid, 'to' => 'discard']);
        self::assertCount(1, $state->table->zone('discard')->items);
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'discard:'.$uid, 'to' => 'hand:p0']);

        // and return a card onto the stock
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$uid, 'to' => 'stock']);
        self::assertSame($uid, self::uidOf($state, 'stock'));
        self::assertCount(35, $state->table->zone('stock')->items);
    }

    public function testDrawingFromStockAlwaysSucceedsEvenWithAStaleUid(): void
    {
        $state = $this->state();
        $trueTop = self::uidOf($state, 'stock');
        // a buried card - what a client's stale, pre-race view might have
        // shown as "the top" by the time this request is actually processed
        $stale = self::uidOf($state, 'stock', 0);

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.$stale, 'to' => 'hand:p0']);

        // the requested uid is irrelevant - drawing a hidden card always
        // hands over whatever is actually on top, never fails on a mismatch
        self::assertCount(1, $state->table->hand('p0')->items);
        self::assertSame($trueTop, self::uidOf($state, 'hand:p0'));
        self::assertCount(35, $state->table->zone('stock')->items);
    }

    public function testDrawingFromDiscardAlwaysSucceedsEvenWithAStaleUid(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        $handUid = self::uidOf($state, 'hand:p0');
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$handUid, 'to' => 'discard']);
        $trueTop = self::uidOf($state, 'discard');

        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'discard:doesnotexist', 'to' => 'hand:p1']);

        self::assertCount(1, $state->table->hand('p1')->items);
        self::assertSame($trueTop, self::uidOf($state, 'hand:p1'));
        self::assertTrue($state->table->zone('discard')->isEmpty());
    }

    public function testDrawingFromAnEmptyPileFails(): void
    {
        $state = $this->state();

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'discard:anything', 'to' => 'hand:p0']);
    }

    public function testAnotherPlayersHandCardIsOutOfReach(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p1']);
        $uid = self::uidOf($state, 'hand:p1');

        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$uid, 'to' => 'hand:p0']);
    }

    public function testFlipTogglesAMiddleCard(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        $uid = self::uidOf($state, 'hand:p0');
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$uid, 'to' => 'middle']);

        // any player may flip, not just the one who played it
        $this->game->applyMove($state, 'p1', ['action' => 'flip', 'uid' => $uid]);
        self::assertFalse($state->table->zone('middle')->top()['faceUp']);
        $this->game->applyMove($state, 'p0', ['action' => 'flip', 'uid' => $uid]);
        self::assertTrue($state->table->zone('middle')->top()['faceUp']);
    }

    public function testOfferIsAcceptedWithAFreshUid(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        $uid = self::uidOf($state, 'hand:p0');
        $card = $state->table->hand('p0')->top()['card'];

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$uid, 'to' => 'hand:p1']);
        // the card stays with the offerer until the receiver accepts
        self::assertCount(1, $state->table->hand('p0')->items);
        self::assertSame(['to' => 'p1', 'uid' => $uid], $state->data['offers']['p0']);

        $this->game->applyMove($state, 'p1', ['action' => 'accept_offer', 'from' => 'p0']);
        self::assertTrue($state->table->hand('p0')->isEmpty());
        self::assertCount(1, $state->table->hand('p1')->items);
        self::assertSame($card, $state->table->hand('p1')->top()['card']);
        // a fresh uid keeps the hand-over free of a traceable FLIP glide
        self::assertNotSame($uid, self::uidOf($state, 'hand:p1'));
        self::assertSame([], $state->data['offers']);
    }

    public function testOfferCanBeDeclinedByReceiverOrWithdrawnBySender(): void
    {
        $state = $this->state(3);
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        $uid = self::uidOf($state, 'hand:p0');

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$uid, 'to' => 'hand:p1']);
        // an uninvolved player cannot touch the offer
        try {
            $this->game->applyMove($state, 'p2', ['action' => 'decline_offer', 'from' => 'p0']);
            self::fail('p2 is neither sender nor receiver');
        } catch (InvalidMoveException) {
        }
        $this->game->applyMove($state, 'p1', ['action' => 'decline_offer', 'from' => 'p0']);
        self::assertSame([], $state->data['offers']);
        self::assertCount(1, $state->table->hand('p0')->items);

        // the sender may withdraw their own offer
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$uid, 'to' => 'hand:p2']);
        $this->game->applyMove($state, 'p0', ['action' => 'decline_offer', 'from' => 'p0']);
        self::assertSame([], $state->data['offers']);
    }

    public function testMovingAnOfferedCardCancelsTheOffer(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        $uid = self::uidOf($state, 'hand:p0');
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$uid, 'to' => 'hand:p1']);

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$uid, 'to' => 'middle']);

        self::assertSame([], $state->data['offers']);
        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p1', ['action' => 'accept_offer', 'from' => 'p0']);
    }

    public function testViewHidesFacesAndOffersFromOthers(): void
    {
        $state = $this->state(3);
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        $uid = self::uidOf($state, 'hand:p0');
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$uid, 'to' => 'middle']);
        $this->game->applyMove($state, 'p0', ['action' => 'flip', 'uid' => $uid]);
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p1']);
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'card:'.self::uidOf($state, 'hand:p1'), 'to' => 'hand:p2']);

        $viewP0 = $this->game->buildView($state, 'p0');
        // a face-down middle card exposes no face
        self::assertNull($viewP0['middle']['chains'][0][0]['card']);
        // other players' hands are uids only
        $allSeatData = json_encode($viewP0['seats']);
        self::assertStringNotContainsString('"rank"', $allSeatData);
        // the offer from p1 to p2 is invisible to p0
        self::assertSame([], $viewP0['incomingOffers']);

        $viewP2 = $this->game->buildView($state, 'p2');
        self::assertCount(1, $viewP2['incomingOffers']);
        self::assertSame('p1', $viewP2['incomingOffers'][0]['fromId']);

        // spectators get a view without hand or moves
        $spectator = $this->game->buildView($state, null);
        self::assertFalse($spectator['isPlayer']);
        self::assertSame([], $spectator['hand']);
        self::assertSame([], $spectator['moves']);
    }

    public function testSeatsAreArrangedAroundTheTable(): void
    {
        $state = $this->state(6);
        $view = $this->game->buildView($state, 'p0');

        self::assertCount(2, $view['seats']['left']);
        self::assertCount(1, $view['seats']['top']);
        self::assertCount(2, $view['seats']['right']);
        // ring order: p1 sits bottom-left, p2 above it, p3 across the top,
        // p4 and p5 come down the right edge
        self::assertSame('p2', $view['seats']['left'][0]['id']);
        self::assertSame('p1', $view['seats']['left'][1]['id']);
        self::assertSame('p3', $view['seats']['top'][0]['id']);
        self::assertSame('p4', $view['seats']['right'][0]['id']);
        self::assertSame('p5', $view['seats']['right'][1]['id']);

        // the ring rotates with the viewer
        $viewP3 = $this->game->buildView($state, 'p3');
        self::assertSame('p5', $viewP3['seats']['left'][0]['id']);
        self::assertSame('p4', $viewP3['seats']['left'][1]['id']);
        self::assertSame('p0', $viewP3['seats']['top'][0]['id']);
    }

    public function testStockViewExposesEveryCardAsAnIndividuallyReachableLayer(): void
    {
        $state = $this->state();
        $view = $this->game->buildView($state, 'p0');

        // 36 cards - the bottom one is the separate trump peek, never a layer
        self::assertCount(35, $view['stock']['layers']);
        // every layer is already wired as a reachable move, not just the top
        foreach ($view['stock']['layers'] as $layer) {
            self::assertContains('hand:p0', $view['moves']['stock:'.$layer['uid']]);
        }
        self::assertNotNull($view['stock']['trumpPeek']);
    }

    public function testStockCollapsesToASingleFaceUpLayerWhenOnlyTheTrumpCardIsLeft(): void
    {
        $state = $this->state();
        // draw every card except the trump card itself
        while (\count($state->table->zone('stock')->items) > 1) {
            $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        }

        $view = $this->game->buildView($state, 'p0');
        self::assertNull($view['stock']['trumpPeek']);
        self::assertCount(1, $view['stock']['layers']);
        self::assertNotNull($view['stock']['layers'][0]['card'] ?? $view['stock']['layers'][0]['rank'] ?? null);
    }

    public function testStackingOntoAMiddleCardAppendsItToTheChain(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        $attackUid = self::uidOf($state, 'hand:p0');
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$attackUid, 'to' => 'middle']);

        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p1']);
        $defendUid = self::uidOf($state, 'hand:p1');

        // before stacking, p1's hand card can already target "stack:<attackUid>"
        $viewBefore = $this->game->buildView($state, 'p1');
        self::assertContains('stack:'.$attackUid, $viewBefore['moves']['card:'.$defendUid]);

        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'card:'.$defendUid, 'to' => 'stack:'.$attackUid]);

        self::assertSame($attackUid, $state->table->zone('middle')->items[0]['uid']);
        self::assertSame($defendUid, $state->table->zone('middle')->items[1]['uid']);
        self::assertSame($attackUid, $state->table->zone('middle')->items[1]['stackOn']);

        $view = $this->game->buildView($state, 'p0');
        self::assertCount(1, $view['middle']['chains']);
        // the chain is a flat, root-to-leaf ordered list - not nested - so
        // every card renders as a direct sibling of its pile-mates
        self::assertSame([$attackUid, $defendUid], array_column($view['middle']['chains'][0], 'uid'));
        self::assertFalse($view['middle']['chains'][0][0]['isLeaf']);
        self::assertTrue($view['middle']['chains'][0][1]['isLeaf']);
        // only the current top of the pile (the defend card) may be freshly targeted
        self::assertSame([$defendUid], $view['middle']['leafUids']);
    }

    public function testChainsCanGrowArbitrarilyDeepAndEveryCardStaysVisible(): void
    {
        $state = $this->state();
        $uids = [];
        foreach (['p0', 'p1', 'p0'] as $playerId) {
            $this->game->applyMove($state, $playerId, ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:'.$playerId]);
            $uids[] = self::uidOf($state, 'hand:'.$playerId);
        }
        [$base, $mid, $top] = $uids;

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$base, 'to' => 'middle']);
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'card:'.$mid, 'to' => 'stack:'.$base]);
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$top, 'to' => 'stack:'.$mid]);

        $view = $this->game->buildView($state, 'p0');
        self::assertCount(1, $view['middle']['chains']);
        // every card in the chain is present and visible, in root-to-leaf order
        self::assertSame([$base, $mid, $top], array_column($view['middle']['chains'][0], 'uid'));
        self::assertSame([$top], $view['middle']['leafUids']);
    }

    public function testCannotStackOntoACardThatAlreadyHasSomethingOnIt(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        $base = self::uidOf($state, 'hand:p0');
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$base, 'to' => 'middle']);

        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p1']);
        $first = self::uidOf($state, 'hand:p1');
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'card:'.$first, 'to' => 'stack:'.$base]);

        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p1']);
        $second = self::uidOf($state, 'hand:p1');

        try {
            $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'card:'.$second, 'to' => 'stack:'.$base]);
            self::fail('the base already has a card stacked on it - only its new top may be targeted');
        } catch (InvalidMoveException) {
            self::assertCount(1, $state->table->hand('p1')->items);
        }
    }

    public function testTakingTheTopOfAChainLeavesTheRestBehind(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        $base = self::uidOf($state, 'hand:p0');
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$base, 'to' => 'middle']);
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p1']);
        $top = self::uidOf($state, 'hand:p1');
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'card:'.$top, 'to' => 'stack:'.$base]);

        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$top, 'to' => 'hand:p0']);

        self::assertCount(1, $state->table->zone('middle')->items);
        self::assertSame($base, $state->table->zone('middle')->items[0]['uid']);
        self::assertCount(1, $state->table->hand('p0')->items);
        self::assertSame($top, self::uidOf($state, 'hand:p0'));
    }

    public function testTakingACardFromUnderneathCarriesTheWholePileAboveItAlong(): void
    {
        $state = $this->state();
        $uids = [];
        foreach (['p0', 'p1', 'p0'] as $playerId) {
            $this->game->applyMove($state, $playerId, ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:'.$playerId]);
            $uids[] = self::uidOf($state, 'hand:'.$playerId);
        }
        [$base, $mid, $top] = $uids;
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$base, 'to' => 'middle']);
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'card:'.$mid, 'to' => 'stack:'.$base]);
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$top, 'to' => 'stack:'.$mid]);

        // taking the base out from under the pile brings mid and top along too
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'card:'.$base, 'to' => 'discard']);

        self::assertTrue($state->table->zone('middle')->isEmpty());
        $discardUids = array_column($state->table->zone('discard')->items, 'uid');
        self::assertSame([$base, $mid, $top], $discardUids);
        // stackOn is cleared once they land in a normal pile
        foreach ($state->table->zone('discard')->items as $item) {
            self::assertNull($item['stackOn']);
        }
    }

    public function testCannotStackACardOntoItselfOrAMissingTarget(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        $uid = self::uidOf($state, 'hand:p0');

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$uid, 'to' => 'stack:'.$uid]);
            self::fail('a card cannot stack onto itself');
        } catch (InvalidMoveException) {
            self::assertCount(1, $state->table->hand('p0')->items);
        }

        try {
            $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$uid, 'to' => 'stack:doesnotexist']);
            self::fail('the stack target must exist in the middle');
        } catch (InvalidMoveException) {
            self::assertCount(1, $state->table->hand('p0')->items);
        }
    }

    public function testALoneMiddleCardCanJoinAnotherMiddleCardsChain(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        $a = self::uidOf($state, 'hand:p0');
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$a, 'to' => 'middle']);
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        $b = self::uidOf($state, 'hand:p0');
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$b, 'to' => 'middle']);

        // two independent lone cards in the middle - moving one onto the
        // other joins them into a single chain, just like a hand card would
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$b, 'to' => 'stack:'.$a]);

        $view = $this->game->buildView($state, 'p0');
        self::assertCount(1, $view['middle']['chains']);
        self::assertSame([$a, $b], array_column($view['middle']['chains'][0], 'uid'));
    }

    public function testALeafCanBePulledBackDownToLieOnItsOwnAgain(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        $base = self::uidOf($state, 'hand:p0');
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$base, 'to' => 'middle']);
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p1']);
        $top = self::uidOf($state, 'hand:p1');
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'card:'.$top, 'to' => 'stack:'.$base]);

        // pull the defend card back down to lie by itself, undoing the stack
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$top, 'to' => 'middle']);

        self::assertNull($state->table->zone('middle')->items[1]['stackOn']);
        $view = $this->game->buildView($state, 'p0');
        self::assertCount(2, $view['middle']['chains']);
        self::assertSame([$base], array_column($view['middle']['chains'][0], 'uid'));
        self::assertSame([$top], array_column($view['middle']['chains'][1], 'uid'));
    }

    public function testCannotStackACardOntoADescendantOfItself(): void
    {
        $state = $this->state();
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p0']);
        $base = self::uidOf($state, 'hand:p0');
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$base, 'to' => 'middle']);
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'stock:'.self::uidOf($state, 'stock'), 'to' => 'hand:p1']);
        $top = self::uidOf($state, 'hand:p1');
        $this->game->applyMove($state, 'p1', ['action' => 'move', 'from' => 'card:'.$top, 'to' => 'stack:'.$base]);

        // base cannot stack onto its own descendant - that would be a cycle
        $this->expectException(InvalidMoveException::class);
        $this->game->applyMove($state, 'p0', ['action' => 'move', 'from' => 'card:'.$base, 'to' => 'stack:'.$top]);
    }
}
