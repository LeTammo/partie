# Dice

For Yahtzee and any future dice game.

## `Dice` (`src/Game/Core/Model/Dice.php`)

```php
$state->dice[] = new Dice(maxFaces: 6);

$state->dice[0]->roll();          // random_int(1, maxFaces); no-op while locked
$state->dice[0]->toggleLock();    // "hold" a die between rolls
$state->dice[0]->value;           // current face
```

`GameState::$dice` is a plain `list<Dice>` - a die doesn't know its own
index or lock-eligibility rules; that's per-game (e.g. "can't hold before
the first roll"), so your renderer computes it.

## `components/die.html.twig`

An SVG die face:

```twig
{% include 'components/die.html.twig' with {value: die.value, face: '#5b87b5', pip: '#faf7f2', class: 'size-20'} only %}
```

`face`/`pip` are just colors - Yahtzee uses the current player's color for
`face`, but there's no rule tying them to anything.

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

return [
    'dice' => $dice,
    'dieFace' => $state->currentPlayer()->color,
    'diePip' => '#faf7f2',
    'hasRolled' => $hasRolled,
    'canRoll' => $myTurn && $rollsLeft > 0,
    'rollsLeft' => $rollsLeft,
    'myTurn' => $myTurn,
];
```

```twig
{% include 'components/dice_tray.html.twig' with {
    dice: view.dice, dieFace: view.dieFace, diePip: view.diePip,
    hasRolled: view.hasRolled, canRoll: view.canRoll, rollsLeft: view.rollsLeft, myTurn: view.myTurn,
} %}
```

Include it *without* `only` - it needs `lobby` (inherited from the
including template) to build its own move-form actions.

Holding a die is wired through `optimistic#toggle` (instant ring toggle);
rolling is wired through `optimistic#pending` (every unheld die wobbles
until the morph delivers the real values) - see
[optimism.md](optimism.md). Both are already inside the component; you
don't add this wiring yourself.

## Move handling

```php
'roll' => $this->roll($state),
'toggle' => $this->toggleLock($state, $this->intParam($payload, 'die')),
'score' => $this->scoreCategory($state, $playerId, $this->stringParam($payload, 'category')),
```

A roll's result is server-random by definition - the client can never
predict it, so `optimistic#pending`'s wobble is *feedback*, not a
prediction. Don't try to guess dice values client-side. See the
"server-random vs. predictable" boundary in
[optimism.md](optimism.md#server-random-vs-predictable).

## Shared translation strings

The tray's own labels (`dice.roll`, `dice.hold`, `dice.release`,
`dice.held`, `dice.rolls_left`, `dice.no_rolls_left`) live in
`translations/messages.{en,de}.yaml`, not your game's domain - a shared
component can't depend on its first consumer's translation domain
(`{% trans_default_domain %}` doesn't apply across an `{% include %}`).
Your game-specific strings (category names, score-related text) still go in
your own `translations/<id>.{en,de}.yaml`.
