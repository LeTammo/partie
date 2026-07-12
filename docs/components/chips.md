# Chips

For any game with a chip economy: Blackjack, future Poker.

## Models & views

`Chip` (`src/Game/Core/Model/Chip.php`) is `{color, label, value}`.
`ChipViews` (`src/Game/Core/View/ChipViews.php`) maps amounts to
denomination chips with palette colors:

```php
ChipViews::single(50);            // one 50 chip (softblue)
ChipViews::stack(85);             // [50, 20, 10, 5] chips, capped at 8
ChipViews::stack(240, maxChips: 4);
```

Denominations: 100, 50, 20, 10, 5, 1 - each with a fixed palette color.

## `components/chip.html.twig`

One chip: colored disc, dashed edge, white center, optional label:

```twig
{% include 'components/chip.html.twig' with {color: chip.color, label: chip.label, size: 'size-6'} only %}
```

## `components/chip_stack.html.twig`

An overlapping row plus total - a player's balance at a glance:

```twig
{% include 'components/chip_stack.html.twig' with {chips: p.chipStack, total: p.chips, size: 'size-5'} only %}
```

Build `p.chipStack` in your renderer via `ChipViews::stack()` (see
Blackjack's `GameRenderer`). Chip *amounts* stay plain integers in
`$state->data['chips']` - the chip objects are pure presentation.
