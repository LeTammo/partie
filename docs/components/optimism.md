# Optimistic UI

**Every user action is optimistic by default.** A game opts a form *out*,
it never opts one in.

## The baseline: `assets/optimistic.js`

No Stimulus, no per-game code. On `turbo:submit-start`, any `<form>` inside
`#game-area` gets its submitter disabled and a `.pending` class added; both
are undone on `turbo:submit-end` (covers a failed fetch - the morph itself
usually re-renders the button anyway). That's it: every form gets
"disable + fade" the instant it submits.

Opt out on the rare form that needs to handle its own pending state:

```twig
<form data-optimistic="off">
```

Hooked on `turbo:submit-start`, not the raw `submit` event, deliberately -
at `submit-start` the request body is already built, so disabling the
submitter can no longer drop its `name`/`value` from the payload (matters
for a button that *carries* the submitted value, like a bet amount or a
swap choice).

## Beyond the baseline: the `optimistic` controller

For a predictable result, mutate the DOM immediately instead of just
disabling the button. Attach `data-controller="optimistic"` once per game
root, then configure each form with action params - one controller instance
serves any number of forms.

**The forms carry their own `<template>`, server-rendered with the exact
markup the real render would produce.** The primitives below only
clone/move/toggle what Twig already rendered; they never build markup in
JS. That's the point: if the template changes, the optimistic version can't
silently drift from it.

| Action | Params | Use it for |
|---|---|---|
| `insert` | `into` (selector, default: the form's own button), `where` (`append` default, or `last-empty`) | Placing a new, fully-known element |
| `replace` | `target` (selector, default: the form's own button) | Swapping a button for its "already happened" state |
| `move` | `to` (selector), `mode` (`replace` default, or `append`), `id`, `from` (selector, default: the form's own `[data-flip-id]`), `hide` | Moving an already-rendered element to a new slot |
| `ghost` | `from` (selector, default: the form's `[data-flip-id]`/`<template>`), `to` (selector) | A throwaway stand-in traveling somewhere, when the real element can't (a face-down card, an unknown die roll) |
| `toggle` | `class` (space-separated) | Toggling a class on the clicked element |
| `pending` | `selector`, `class`, `unless` (selector filter) | Marking several elements "waiting" |

Every primitive checks `event.defaultPrevented` first, so an earlier action
in the same `data-action` (e.g. `choice-dialog#require`) can veto it -
Stimulus runs actions in attribute order.

### `insert` - placing a new element (Tic-Tac-Toe)

```twig
<form data-action="submit->optimistic#insert">
    <button type="submit"></button>
    <template><span style="color: {{ view.myColor }}">{{ view.myVariant == 'x' ? '✕' : '◯' }}</span></template>
</form>
```

Default `into` is the form's own button, default `where` is `append` - the
template's content gets appended into the button that was just clicked.

With an explicit `into` (Four in a Row - the column, not the button, and the
disc falls to the lowest *empty* cell):

```twig
<form data-action="submit->optimistic#insert"
      data-optimistic-into-param="[data-col='{{ col.column }}']"
      data-optimistic-where-param="last-empty">
```

### `replace` - an "already happened" state (DicePoker scoring)

```twig
<form data-action="submit->optimistic#replace">
    <button type="submit">+{{ cell.potential }}</button>
    <template><span class="anim-pop">{{ cell.potential }}</span></template>
</form>
```

### `move` - moving a known element (Mau-Mau playing a card)

```twig
<form data-action="submit->optimistic#move"
      data-optimistic-to-param="#mm-dropzone"
      data-optimistic-id-param="mm-top-{{ card.identity }}">
    <button type="submit">{% include 'components/card.html.twig' with card|merge({flip: 'mm-' ~ card.identity}) only %}</button>
</form>
```

Moves the form's `[data-flip-id]` element into `to`, rewrites its `id` to
what the server will render (so the confirming morph patches in place
instead of replaying an entry animation), glides it there, and hides the
now-empty form (`mode: 'replace'`'s default).

### `ghost` - a stand-in for something not yet known (Mau-Mau/Rummy drawing)

```twig
<form data-action="submit->optimistic#ghost"
      data-optimistic-from-param="#mm-pile-back" data-optimistic-to-param="#mm-handzone">
```

The actual drawn card is server-side and unknown - a clone of the face-down
pile card travels toward the hand, then the morph deals the real card in
with its own entry animation.

### `toggle` / `pending` - dice (DicePoker)

```twig
<button data-die data-locked="{{ die.locked ? 'true' : 'false' }}"
        data-action="click->optimistic#toggle" data-optimistic-class-param="ring-2 ring-softblue-300">
```

```twig
<form data-action="submit->optimistic#pending"
      data-optimistic-selector-param="[data-die]" data-optimistic-class-param="anim-rolling"
      data-optimistic-unless-param="[data-locked='true']">
```

## `choice-dialog` - a form that needs a value first

For "this form can't submit until the user picks something" (Mau-Mau's
jack needs a wish suit before it can be played):

```twig
<form data-action="submit->choice-dialog#require submit->optimistic#move"
      data-choice-dialog-dialog-param="#wish-dialog" data-choice-dialog-name-param="wish"
      data-optimistic-to-param="#mm-dropzone" data-optimistic-id-param="mm-top-{{ card.identity }}">
    <input type="hidden" name="wish" value="">
    <button type="submit">...</button>
</form>

<dialog id="wish-dialog" data-action="close->choice-dialog#closed">
    <button type="button" data-action="choice-dialog#pick" data-choice-dialog-value-param="hearts">♥</button>
</dialog>
```

`require` must come *before* `optimistic#move` in `data-action` - if the
named input is empty, it `preventDefault()`s the submission and opens the
dialog; `optimistic#move` then no-ops because the event was already
prevented. Picking a value fills the input, closes the dialog, and calls
`form.requestSubmit()` - a fresh submit event, this time with `require`
passing through and `move` running. Closing the dialog any other way
(cancel, backdrop click) resets the input to empty, so a cancelled choice
can be retried cleanly.

This works identically whether the form was submitted by click or by a
`zone_drop` drag - a drop just calls `requestSubmit()` too.

## Server-random vs. predictable

A server-random result (a drawn card, a dice roll, a dealt hand) **can
never be predicted client-side.** For those, "optimistic" only means
instant *feedback* - `ghost`, `pending`, or the global lock/fade baseline -
and the Turbo morph delivers the real result moments later. A predictable
result (you already know exactly which card moves, exactly what a score
becomes) gets full mutation via `insert`/`replace`/`move`. Don't try to
guess a random outcome to make it "more optimistic" - there's nothing to
guess, and a wrong guess just means visible correction later.
