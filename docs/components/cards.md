# Cards

For any card game: Mau-Mau, Rummy, Koepknack, Blackjack, Yahtzee's dice
aside.

## The card itself

`PlayingCard` (`src/Game/Core/Card/PlayingCard.php`) is immutable: a `Suit`
+ `Rank`, or a joker.

```php
PlayingCard::of(Suit::Hearts, Rank::Ace);
PlayingCard::jokerCard();
$card->is(Suit::Hearts, Rank::Ace); // bool
```

`Rank`'s backing value is its natural ordering, Ace high (`Rank::Ace->value
=== 14`). `Rank::labelKey()` gives you a localized-label key (`'jack'` →
renders "J" in English, "B" in German); `Suit::symbol()` gives you
`♣ ♠ ♥ ♦`; `Suit::isRed()` tells you the display color.

## Building a deck: `DeckFactory`

```php
DeckFactory::deck32();   // 7 to Ace, shuffled - Mau-Mau, Koepknack
DeckFactory::deck52();   // 2 to Ace, shuffled - Blackjack
DeckFactory::deck55();   // 2 to Ace + 3 jokers
DeckFactory::deck110();  // two 2-to-Ace decks + 6 jokers - Rummy
```

Deal a hand by splicing it:

```php
$deck = DeckFactory::deck52();
$hand = array_splice($deck, 0, 5);
```

## Draw/discard piles: `Piles::draw()`

Any game with a face-down draw pile eventually needs "the pile ran dry,
shuffle the discard back in, keep its top card in place." Don't
reimplement this - every card game so far needed it exactly once:

```php
$drawn = Piles::draw($state->data['drawPile'], $state->data['discard'], 1, function () use ($state) {
    $state->logGameEvent('log.<id>.reshuffled');
});
```

Returns the drawn cards - fewer than requested if the discard pile can't
refill any further (e.g. only its own top card remains). `$onReshuffle`
fires once per reshuffle so you can log it; pass `null` if you don't care.

## Rendering: `CardPresenter`

```php
CardPresenter::view($card);   // {rank, suit, red, joker, identity}
CardPresenter::views($cards); // list of the above
```

`identity` is a stable, card-game-agnostic string (`'ace-♥'`, or `'joker'`)
- use it everywhere you'd otherwise recompute rank+suit by hand: DOM ids,
FLIP ids, translation params.

## `components/card.html.twig`

```twig
{% include 'components/card.html.twig' with card|merge({
    key: 'mm-hand-' ~ card.identity,
    flip: 'mm-' ~ card.identity,
    exit: 'none',
}) only %}
```

- `key` - the DOM `id`. Give every rendered card a unique one so Turbo's
  morph can match old/new elements instead of replacing the whole hand.
- `flip` - the FLIP id (`data-flip-id`). Cards sharing a `flip` id across a
  morph glide between their old and new position instead of popping.
- `exit` - the exit-ghost animation when a card's `flip` id disappears
  (`'fade'` default, `'fly-left'`, `'fly-right'`, or `'none'` to skip it).
- `{back: true, key: '...'}` renders a face-down card.

See [ui-kit.md](ui-kit.md) for how `key`/`flip`/`exit` work under the hood.

## Selecting cards from a hand: the `cards` controller

Multi-select toggle that mirrors the selection into a hidden comma-separated
input, so a plain form can submit it (Rummy's meld/discard):

```twig
<div data-controller="cards">
    {% for card in view.hand %}
        <button type="button" data-cards-target="card" data-index="{{ card.index }}"
                data-action="cards#toggle">
            {% include 'components/card.html.twig' with card only %}
        </button>
    {% endfor %}

    <form method="post" action="{{ path('app_game_move', {code: lobby.code}) }}">
        <input type="hidden" name="cards" data-cards-target="input">
        <button type="submit" name="action" value="discard">Discard</button>
    </form>
</div>
```

Read it back server-side with `$this->intListParam($payload, 'cards')` (see
[engine-and-state.md](engine-and-state.md)).

## Swapping a card between two groups: `dragdrop--group-swap`

For "drag a card from your hand onto a card on the table (or vice versa) to
swap them" (Koepknack). Groups are identified by each item's own radio
input's `name` - no separate group config.

```twig
<div data-controller="dragdrop--group-swap"
     data-dragdrop--group-swap-id-template-value="kk-{group}-{slot}-{identity}">
    <form data-action="submit->dragdrop--group-swap#beforeSubmit">
        {% for card in view.middle %}
            <label data-dragdrop--group-swap-target="item" data-slot="{{ loop.index0 }}"
                   draggable="true"
                   data-action="dragstart->dragdrop--group-swap#dragStart dragend->dragdrop--group-swap#dragEnd
                                dragover->dragdrop--group-swap#dragOver drop->dragdrop--group-swap#drop dragleave->dragdrop--group-swap#dragLeave">
                <input type="radio" name="middle" value="{{ loop.index0 }}" class="peer sr-only">
                {% include 'components/card.html.twig' with card|merge({flip: 'kk-' ~ card.identity}) only %}
            </label>
        {% endfor %}
        {# same block again for view.hand, with name="hand" #}
        <button type="submit" name="action" value="swap" data-dragdrop--group-swap-target="submitButton">Swap</button>
    </form>
</div>
```

Dropping (or checking both radios + submitting) swaps the two cards'
positions and glides them there. `idTemplateValue`'s `{group}`/`{slot}`/
`{identity}` placeholders build the new DOM id from the destination slot
and the moved card's own `flip`-id identity - so give it a
`{game}-{group}-{slot}-{identity}` shape matching how your renderer keys
that slot.

## Dragging a card onto a drop zone: `dragdrop--zone-drop`

For "drag this card onto that pile" where dropping just submits the card's
own form (Mau-Mau: hand card → discard pile, draw pile → hand). Sources and
zones pair up via a shared `data-pair` value:

```twig
<div data-controller="dragdrop--zone-drop">
    <div data-dragdrop--zone-drop-target="zone" data-pair="play"
         data-action="dragover->dragdrop--zone-drop#dragOver drop->dragdrop--zone-drop#drop dragleave->dragdrop--zone-drop#dragLeave">
        {# discard pile #}
    </div>

    <form>
        <button type="submit" draggable="true" data-pair="play"
                data-dragdrop--zone-drop-target="source"
                data-action="dragstart->dragdrop--zone-drop#dragStart dragend->dragdrop--zone-drop#dragEnd">
            {% include 'components/card.html.twig' with card only %}
        </button>
    </form>
</div>
```

A valid drop submits the source's own `<form>` - dropping is just an
alternate way to trigger whatever the button already does on click. Need to
intercept before it submits (Mau-Mau's jack needs a wish first)? Listen for
the cancelable `zone-drop:drop` event, or use
[choice-dialog](optimism.md#choice-dialog) instead of a custom listener.

One controller instance can serve several source(s)→zone relationships at
once - just give each pair its own `data-pair` value (Mau-Mau uses `"play"`
and `"draw"` on the same controller).

Don't merge `group_swap`/`zone_drop`/`grid_move` into one controller - their
state machines differ enough that a forced union would be less reusable,
not more.
