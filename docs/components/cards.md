# Cards

For any card game: Mau-Mau, Rummy, Koepknack, Blackjack, Solitaire,
Eleven Out.

## The card itself

`PlayingCard` (`src/Game/Core/Card/PlayingCard.php`) is immutable: a `Suit`
+ `Rank`, or a joker.

```php
PlayingCard::of(Suit::Hearts, Rank::Ace);
PlayingCard::jokerCard();
$card->is(Suit::Hearts, Rank::Ace); // bool
```

`Rank`'s backing value is its natural ordering, Ace high (`Rank::Ace->value
=== 14`). `Rank::labelKey()` gives you a localized-label key; `Suit::symbol()`
gives you `♣ ♠ ♥ ♦`; `Suit::isRed()` the display color.

**Custom-face cards.** For games whose cards are colored numbers/symbols
instead of suits (Eleven Out), use `CustomCard` (`{color, value}`,
`identity()` gives a stable `"color-value"` string):

```php
new CustomCard('red', '11');
```

## Building a deck: `DeckFactory`

```php
DeckFactory::deck32();   // 7 to Ace, shuffled - Mau-Mau, Koepknack
DeckFactory::deck52();   // 2 to Ace, shuffled - Blackjack, Solitaire
DeckFactory::deck55();   // 2 to Ace + 3 jokers
DeckFactory::deck110();  // two 2-to-Ace decks + 6 jokers - Rummy

DeckFactory::customRangeDeck(['red', 'yellow', 'green', 'blue'], 1, 20);  // Eleven Out
DeckFactory::customSymbolDeck($colors, ['A', 'B', 'C']);                  // arbitrary values
```

Deal into zones (see [zones-and-tables.md](zones-and-tables.md)):

```php
$table->hand($player->id)->push(...array_splice($deck, 0, 5));
```

## Draw/discard piles: `Piles::draw()`

"The pile ran dry, shuffle the discard back in, keep its top card" - works
directly on zone items:

```php
$drawn = Piles::draw($table->zone('stock')->items, $table->zone('discard')->items, 1, function () use ($state) {
    $state->logGameEvent('log.<id>.reshuffled');
});
```

Returns the drawn cards - fewer than requested if the discard can't refill
any further. `$onReshuffle` fires once per reshuffle; pass `null` to skip.

## Rendering: presenters + `components/card.html.twig`

```php
CardPresenter::view($card);         // {rank, suit, red, joker, identity}
CardPresenter::views($cards);
CustomCardPresenter::view($card);   // {value, color, identity}
```

Both presenter shapes render through the same component:

```twig
{% include 'components/card.html.twig' with card|merge({
    key: 'mm-hand-' ~ card.identity,
    flip: 'mm-' ~ card.identity,
    exit: 'none',
}) only %}
```

- `key`/`flip`/`exit` - DOM id, FLIP id, exit-ghost animation (see
  [ui-kit.md](ui-kit.md)).
- `{back: true}` - face-down; `backColor` recolors the back design.
- `size: 'md'` (default) or `'sm'` - never pass size classes manually.
- A `{value, color}` map renders the custom face; the color names
  `red`/`yellow`/`green`/`blue` map to the app palette, anything else
  renders neutral.
- A truly one-off front can override the `front` block via `{% embed %}`.

Piles (stock, discard, waste) render via `components/pile.html.twig` - see
[zones-and-tables.md](zones-and-tables.md).

## Selecting cards from a hand: the `cards` controller

Multi-select toggle that mirrors the selection into a hidden
comma-separated input, so a plain form can submit it (Rummy's
meld/discard):

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

Read it back server-side with `$this->intListParam($payload, 'cards')`.

## Moving cards between zones

Three shapes, three controllers - pick by the interaction, not the game:

1. **Map-driven movement** (`dragdrop--piece-move`): the renderer computes
   a legal-moves map (`sourceKey => [destKeys]`); picking/dragging any
   source onto any legal zone submits `from`/`to`. Solitaire (with
   `runs: true` so a card brings its stacked run along). Full reference in
   [tokens-and-boards.md](tokens-and-boards.md).

2. **Drop-submits-the-form** (`dragdrop--zone-drop`): every playable card
   is its own form; dropping it on a paired zone just submits that form
   (Mau-Mau's hand → discard, draw pile → hand). Sources and zones pair up
   via `data-pair`. Interceptable via the cancelable `zone-drop:drop`
   event or [choice-dialog](optimism.md) (Mau-Mau's jack wish).

3. **Slot swapping** (`dragdrop--group-swap`): drag a card onto a card of
   the other group (or check both radios) to swap their positions
   (Koepknack). `idTemplateValue`'s `{group}`/`{slot}`/`{identity}`
   placeholders rebuild DOM ids after the swap.
