# UI kit & animation conventions

Shared presentation pieces that apply regardless of what the game is played
with.

## Buttons: `.btn` classes

`assets/styles/app.css` defines the shape once and four color variants:

```twig
<button class="btn btn-primary">{{ 'yahtzee.roll'|trans }}</button>
<button class="btn btn-neutral">{{ 'maumau.pass'|trans }}</button>
<button class="btn btn-confirm">{{ 'koepknack.new_round'|trans }}</button>
<button class="btn btn-info">{{ 'rummy.takeback'|trans }}</button>
```

They're declared in `@layer components`, so an extra Tailwind utility class
on the same element still overrides them correctly:

```twig
<button class="btn btn-primary px-10 py-4 text-xl disabled:opacity-40">
```

## Player banners: `player_banner.html.twig`

The one player summary component - dot, nickname, current-turn ring, plus
an `extra` block for whatever a game shows alongside (card count, points,
chips, badges). Three layout variants cover every shape a game needs:

```twig
{% embed 'components/player_banner.html.twig' with {p: p} %}                    {# 'inline' #}
{% embed 'components/player_banner.html.twig' with {p: p, variant: 'tile'} %}   {# centered two-line (Koepknack) #}
{% embed 'components/player_banner.html.twig' with {p: p, variant: 'row'} %}    {# seat header (Blackjack) #}
    {% block extra %}
        <span>{{ p.cardCount }}</span>
    {% endblock %}
{% endembed %}
```

`p` comes from `PlayerViews::build()`. `dim: true` fades an
eliminated/broke player. A new summary shape means a new variant on this
component, not custom markup in a game template.

## FLIP glides & exit ghosts: `key` / `flip` / `exit`

Every game element that should animate across a Turbo morph opts in with
these three things:

- **`key`** → the DOM `id`. Give a re-rendered element a stable, unique id
  so the morph patches it in place instead of replacing it.
- **`flip`** → `data-flip-id`, a stable *identity* independent of position.
  Two renders of the same `flip` id, in different places, glide from the
  old position to the new one (`assets/flip.js` snapshots every
  `[data-flip-id]` before a morph, then diffs after).
- **`exit`** → `data-flip-exit`, the ghost animation played when a `flip`
  id disappears entirely (a card leaving the hand, a captured piece):
  `'fade'` (default), `'fly-left'`, `'fly-right'`, or `'none'` to skip it.

```twig
{% include 'components/card.html.twig' with card|merge({
    key: 'mm-hand-' ~ card.identity,
    flip: 'mm-' ~ card.identity,
    exit: 'none',
}) only %}
```

The card, token and chip components all accept `key`/`flip`/`exit`. A
brand-new `flip` id (nothing to glide from) just plays its own CSS entry
animation (`anim-pop`, `anim-deal`, `anim-drop`, ...).

**`data-anim-restart`** - a morph may patch an element *in place*, which
swallows its CSS entry animation. Stamp the element with a value that
changes when the animation should replay, and `flip.js` restarts it after
the morph (the dice components use `data-anim-restart="{{ die.value }}"`
so a re-rolled die always spins):

```twig
<span id="die-0" data-anim-restart="{{ die.value }}" class="anim-roll block">...</span>
```

## Low-level animation primitives

For a custom optimistic effect the `optimistic` controller's primitives
don't cover (see [optimism.md](optimism.md)), reach for
`assets/animation.js` instead of hand-rolling `getBoundingClientRect()` +
the Web Animations API:

```js
import { glideFrom, spawnGhost } from '../animation.js';

const before = el.getBoundingClientRect();
// ...move/reflow el...
glideFrom(el, before); // el visibly slides from its old rect to wherever it now sits

spawnGhost(node.cloneNode(true), before, { travelTo: targetEl }); // a clone travels there, then self-removes
```

`flip.js` and every dragdrop/optimistic controller are built on these two
functions - there's no lower level than this to drop down to.
