# Zones & tables

For any game played on a table of piles, hands and card spots: Mau-Mau,
Rummy, Koepknack, Blackjack, Solitaire, Eleven Out.

## The idea

A **Zone** is a named location that holds pieces (cards, tokens, chips). A
**Table** is the collection of a game's zones and lives on
`$state->table`. Zones are dumb containers - what may move where stays in
your `applyMove()`.

```php
use App\Game\Core\Zone\{Table, Zone, ZoneVisibility};

$table = $state->table = new Table();

$table->add(new Zone('hand:'.$player->id, $player->id, ZoneVisibility::Owner))
    ->push(...array_splice($deck, 0, 5));
$table->add(new Zone('discard'))->push(array_pop($deck));
$table->add(new Zone('stock', visibility: ZoneVisibility::Hidden))->push(...$deck);
```

Conventional keys: `stock`, `discard`, `waste`, `middle`, `dealer`,
`hand:{playerId}`, `meld:{n}`, `foundation:{suit}`, `tableau:{n}`. These
keys double as the source/destination keys of the
[piece-move controller](tokens-and-boards.md#moving-pieces-the-piece-move-controller).

## Working with zones

```php
$zone = $table->zone('discard');   // throws on unknown key
$table->has('hand:'.$viewerId);    // spectators have no hand
$hand = $table->hand($playerId);   // shorthand for zone('hand:'.$playerId)

$zone->push($card, $card2);        // onto the top
$zone->pop();                      // from the top
$zone->top();                      // peek, null when empty
$zone->count(); $zone->isEmpty();
$zone->removeAt($index);           // play card N from a hand
$zone->takeFrom($index);           // a run: everything from N to the top
$zone->clear();                    // empty the zone, returns the items
$zone->items;                      // the raw list when you need array ops

$table->move('stock', 'waste', 3); // top 3 cards across zones
$table->matching('meld:');         // all meld zones, in insertion order
$table->remove('meld:2');          // a taken-back meld
```

`Piles::draw()` works on zone items directly (see [cards.md](cards.md)):

```php
$drawn = Piles::draw($table->zone('stock')->items, $table->zone('discard')->items, 1);
```

## Visibility

`ZoneVisibility::All | Owner | Hidden` plus `$zone->visibleTo($viewerId)`.
Visibility is mutable - Koepknack's round-end reveal flips every hand to
`All`, and back to `Owner` when the next round starts:

```php
$table->hand($player->id)->visibility = ZoneVisibility::All;
```

Zones can also carry free-form metadata for game-specific attributes
(a Rummy meld's type): `$zone->meta['type'] = 'run';`

## Per-item state

A zone's items are whatever your game needs. Solitaire's tableau items are
`['card' => PlayingCard, 'faceUp' => bool]` because facedness there is
per-card, not per-zone.

## Rendering: `pile.html.twig`

A draw/discard/stock pile with count label, empty-slot placeholder, and an
optional built-in move form. Include **without** `only` when using `form`
(it needs `lobby`):

```twig
{% include 'components/pile.html.twig' with {
    back: true,
    key: 'mm-pile-back',
    label: 'maumau.pile'|trans({'%count%': view.drawCount}),
    form: {
        action: 'draw',
        title: 'maumau.draw'|trans,
        disabled: not view.canDraw,
        ghostFrom: '#mm-pile-back',
        ghostTo: '#mm-handzone',
    },
} %}
```

- `back: true` - face-down pile; `top: cardView` - face-up top card;
  neither - dashed empty slot (`emptyIcon`/`emptySymbol` optional,
  colors via `emptyClass`).
- `form.ghostFrom/ghostTo` wire `optimistic#ghost` so drawing animates.
- `buttonAttr` - extra attributes on the button (drag-source wiring);
  without `form` it renders a plain grab button, making the top card a
  drag source (Solitaire's waste).
- `attr` - extra attributes on the wrapper; makes the pile itself a
  piece-move drop zone (Solitaire's foundations, with
  `data-mode="replace"`).

## Rendering: `table_area.html.twig`

A labeled panel of the table. `{% embed %}` it and fill the `body` block:

```twig
{% embed 'components/table_area.html.twig' with {
    title: 'koepknack.middle'|trans,
    active: view.dealerActing,     {# highlight ring #}
    tone: 'accent',                {# 'cream' default | 'white' | 'accent' #}
    rise: true,                    {# entrance animation for reveals #}
    hidden: not view.revealed,     {# render nothing until revealed #}
} %}
    {% block body %}
        ... cards, piles, anything ...
    {% endblock %}
{% endembed %}
```
