# Adding a Game

A game is a `GameDefinition` (rules) + a `GameRenderer` (view data) + a Twig
template. Everything else - cards, dice, boards, drag-and-drop, optimistic
UI - is a reusable building block that already exists in `app/src/Game/Core/`
and `app/assets/`. This page walks through building one from scratch, then
points you at the in-depth reference for each block in `docs/components/`.

## Walkthrough: Tic-Tac-Toe

### 1. The engine

`src/Game/Games/TicTacToe/GameDefinition.php`:

```php
final readonly class GameDefinition extends AbstractGameDefinition
{
    public function __construct(
        private GameRules $rules,
        private GameRenderer $renderer,
    ) {}

    public function getId(): string { return 'tictactoe'; }
    public function getMinPlayers(): int { return 2; }
    public function getMaxPlayers(): int { return 2; }
    // getName(), getDescription(), getIcon() - all just return strings.
}
```

That's it for registration - implementing `GameEngineInterface` (which
`AbstractGameDefinition` does) is enough for the game to show up everywhere:
the tag is applied automatically, so `php bin/console debug:container
--tag=app.game` will list it once the file exists.

### 2. Initial state

```php
public function createInitialState(array $players): GameState
{
    $state = new GameState($this->getId(), $players, new Board(3, 3));
    $state->data['variants'] = [$players[0]->id => 'x', $players[1]->id => 'o'];

    return $state;
}
```

`GameState` gives you a `Board` (for grid games), `$state->dice` (for dice
games), and `$state->data` - a plain array for anything else (scorecards,
phases, hands). See [engine-and-state.md](components/engine-and-state.md).

### 3. Apply a move

```php
public function applyMove(GameState $state, string $playerId, array $payload): void
{
    if (!$state->isPlayersTurn($playerId)) {
        throw new InvalidMoveException('error.not_your_turn');
    }

    $x = $this->intParam($payload, 'x');
    $y = $this->intParam($payload, 'y');

    if (!$state->board->isEmpty($x, $y)) {
        $this->invalidMove('error.cell_taken');
    }

    $state->board->place($x, $y, new Token(ownerId: $playerId, variant: $state->data['variants'][$playerId]));
    $state->logGameEvent('log.tictactoe.placed', ['%x%' => $x + 1, '%y%' => $y + 1]);

    if (null !== ($winnerId = $this->rules->findWinner($state->board))) {
        $state->finish($winnerId);
    } else {
        $state->advanceTurn();
    }
}
```

`intParam`/`stringParam`/`intListParam` read the raw POST payload without
manual casting; `invalidMove` fills in the translation domain for you. See
[engine-and-state.md](components/engine-and-state.md).

### 4. Render it

```php
final class GameRenderer
{
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $myTurn = $state->isViewersTurn($viewerId);

        return [
            'grid' => BoardViews::grid($state->board, static fn (int $x, int $y, ?Token $token) => [
                'x' => $x, 'y' => $y,
                'variant' => $token?->variant,
                'playable' => $myTurn && null === $token,
            ]),
        ];
    }
}
```

`BoardViews::grid()` runs the x/y loop for you - you only write what a cell
looks like. See [tokens-and-boards.md](components/tokens-and-boards.md).

### 5. The template

```twig
<div data-controller="optimistic">
    {% for row in view.grid %}
        {% for cell in row %}
            {% if cell.playable %}
                <form method="post" action="{{ path('app_game_move', {code: lobby.code}) }}"
                      data-action="submit->optimistic#insert">
                    <input type="hidden" name="x" value="{{ cell.x }}">
                    <input type="hidden" name="y" value="{{ cell.y }}">
                    <button type="submit"></button>
                    <template><span>{{ ... }}</span></template>
                </form>
            {% endif %}
        {% endfor %}
    {% endfor %}
</div>
```

No JavaScript. `data-controller="optimistic"` + `optimistic#insert` clones
the `<template>` into the button the instant the form submits - see
[optimism.md](components/optimism.md) for why the markup lives in a
`<template>` instead of a hand-written DOM insert.

### 6. Translations and rules

`translations/tictactoe.{en,de}.yaml`:

```yaml
tictactoe.place: 'Place at %x%, %y%'
error.cell_taken: 'That cell is already taken.'
log.tictactoe.placed: '%player% placed at %x%, %y%'
rules: |-
  Take turns placing your symbol on the grid.
  Get three in a row - horizontally, vertically, or diagonally - to win.
```

The `rules` key is shown in the lobby's rules popover automatically.

### 7. Verify

```bash
cd app
php -l src/Game/Games/TicTacToe/*.php
php bin/console lint:twig templates/game/tictactoe
php bin/console lint:yaml translations
php bin/console debug:container --tag=app.game
php bin/console debug:asset-map
```

Then open it in a browser: every player count in `[getMinPlayers(),
getMaxPlayers()]`, and one deliberately invalid move to check it reverts
cleanly.

## Hello world: the other building blocks

### Cards: a deck, a hand, drawing

```php
$deck = DeckFactory::deck52();               // shuffled list<PlayingCard>
$hand = array_splice($deck, 0, 5);            // deal 5

$drawn = Piles::draw($drawPile, $discardPile, 1, fn () => $state->logGameEvent('log.reshuffled'));
array_push($hand, ...$drawn);                 // draws 1, reshuffling the discard if the pile is empty

$view = CardPresenter::views($hand);          // [{rank, suit, red, joker, identity}, ...]
```

Full reference, including the three drag-and-drop shapes for cards
(group_swap, zone_drop) and the `key`/`flip`/`exit` id conventions:
[cards.md](components/cards.md).

### Tokens & boards: place and move a piece

```php
$board = new Board(8, 8);
$board->place(2, 3, new Token(ownerId: $player->id, outerColor: '#a3b8a3'));
$board->move(2, 3, 3, 4);
```

Rendering a legal-moves map so pieces can be tapped or dragged:
[tokens-and-boards.md](components/tokens-and-boards.md).

### Dice: roll and hold

```php
$state->dice[] = new Dice(maxFaces: 6);
$state->dice[0]->roll();          // no-op if locked
$state->dice[0]->toggleLock();    // "hold" between rolls
```

`components/dice_tray.html.twig` renders a full row of these, wired for
hold/roll, in one include: [dice.md](components/dice.md).

## Reference

| Doc | Covers |
|---|---|
| [engine-and-state.md](components/engine-and-state.md) | `GameEngineInterface`/`AbstractGameDefinition`, `GameState`, `PlayerViews`, autoplay, translations |
| [tokens-and-boards.md](components/tokens-and-boards.md) | `Board`/`Token`, `BoardViews`, the `grid_move` drag-and-drop type |
| [cards.md](components/cards.md) | `PlayingCard`/`DeckFactory`/`Piles`/`CardPresenter`, `cards`/`group_swap`/`zone_drop` |
| [dice.md](components/dice.md) | `Dice`, `components/die.html.twig`, `components/dice_tray.html.twig` |
| [optimism.md](components/optimism.md) | `assets/optimistic.js` defaults, the `optimistic` primitives, `choice-dialog` |
| [ui-kit.md](components/ui-kit.md) | `.btn` classes, `player_chip`, FLIP/exit-ghost `key`/`flip`/`exit` conventions |
