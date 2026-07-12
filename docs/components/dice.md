# Dice

For DicePoker, Ludo, and any future dice game.

## `Dice` (`src/Game/Core/Model/Dice.php`)

```php
$state->dice[] = new Dice(maxFaces: 6);
$state->dice[] = new Dice(faces: ['⚔', '🛡', 'A', 'B', 'C', 'D']);  // custom faces

$state->dice[0]->roll();          // random face; no-op while locked
$state->dice[0]->toggleLock();    // "hold" a die between rolls
$state->dice[0]->value;           // current face number (1-based)
$state->dice[0]->face();          // the displayed symbol: faces[value-1], or value
```

`GameState::$dice` is a plain `list<Dice>` - a die doesn't know its own
index or lock-eligibility rules; that's per-game, so your renderer
computes it.

## `components/die.html.twig`

An SVG die face - pips for numbers, a char for custom faces:

```twig
{% include 'components/die.html.twig' with {value: die.value, face: '#5b87b5', pip: '#faf7f2', class: 'size-20'} only %}
{% include 'components/die.html.twig' with {value: die.value, symbol: die.face, class: 'size-20'} only %}
```

`face`/`pip` are just colors - DicePoker uses the current player's color for
`face`, but there's no rule tying them to anything.

## `components/die_roller.html.twig`

A single die that rolls on click - the whole die is the button (Ludo).
Include **without** `only` (it needs `lobby` for the move form):

```twig
{% include 'components/die_roller.html.twig' with {
    value: view.roll|default(1),
    canRoll: view.canRoll,
    class: 'size-20',
} %}
```

Submits `action=roll` (override via `action`); rolling wobbles the die via
`optimistic#pending` until the morph delivers the real value.

The roll-entry animation replays when `value`/`symbol` changes between
renders. If the same face can legitimately come up twice in a row (Ludo:
game start defaults to face 1, then the first real roll is also a 1), pass
`restartKey` with something that changes on every roll, e.g. a roll counter
from game state - otherwise the morph won't detect a change and the
animation won't replay.

## `components/dice_tray.html.twig`

A full row of dice (hold/release wiring + a roll button), parameterized so
the next dice game gets it for free:

```php
// GameRenderer::buildView()
$dice = [];
foreach ($state->dice as $i => $die) {
    $dice[] = [
        'index' => $i,
        'value' => $die->value,
        'locked' => $die->locked,
        'lockable' => $myTurn && $hasRolled && $rollsLeft > 0,
    ];
}
```

```twig
{% include 'components/dice_tray.html.twig' with {
    dice: view.dice, dieFace: view.dieFace, diePip: view.diePip,
    hasRolled: view.hasRolled, canRoll: view.canRoll, rollsLeft: view.rollsLeft, myTurn: view.myTurn,
} %}
```

Include it *without* `only` - it needs `lobby` to build its move-form
actions. Holding is wired through `optimistic#toggle`, rolling through
`optimistic#pending` - already inside the component.

## Move handling

```php
'roll' => $this->roll($state),
'toggle' => $this->toggleLock($state, $this->intParam($payload, 'die')),
'score' => $this->scoreCategory($state, $playerId, $this->stringParam($payload, 'category')),
```

A roll's result is server-random by definition - the wobble is *feedback*,
not a prediction. Don't guess dice values client-side (see
[optimism.md](optimism.md)).

## Shared translation strings

The tray's labels (`dice.roll`, `dice.hold`, `dice.release`, `dice.held`,
`dice.rolls_left`, `dice.no_rolls_left`) live in
`translations/messages.{en,de}.yaml` - a shared component can't depend on
its first consumer's translation domain. Your game-specific strings still
go in your own `translations/<id>.{en,de}.yaml`.
