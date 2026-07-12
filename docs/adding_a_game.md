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

The renderer's job is to turn state into *component descriptors* - for a
grid game, one `board` map the shared board component can render:

```php
final readonly class GameRenderer
{
    public function buildView(GameState $state, ?string $viewerId): array
    {
        $myTurn = $state->isViewersTurn($viewerId);

        $cells = [];
        foreach (BoardViews::grid($state->board, static fn (int $x, int $y, ?Token $token) => [
            'x' => $x, 'y' => $y, 'token' => $token,
            'playable' => $myTurn && null === $token,
        ]) as $row) {
            foreach ($row as $cell) {
                $cells[] = $cell['playable']
                    ? ['form' => [
                        'fields' => ['x' => $cell['x'], 'y' => $cell['y']],
                        'buttonClass' => '...cell classes...',
                        'template' => ['shape' => 'plain', 'symbol' => '✕', 'symbolColor' => '...'],
                    ]]
                    : ['class' => '...cell classes...', 'token' => /* token params or null */ null];
            }
        }

        return ['board' => ['cols' => 3, 'rows' => 3, 'class' => 'grid gap-2 rounded-3xl bg-cream p-3', 'cells' => $cells]];
    }
}
```

`BoardViews::grid()` runs the x/y loop; the descriptor fields are listed in
[tokens-and-boards.md](components/tokens-and-boards.md). See the real
`TicTacToe\GameRenderer` for the full version.

### 5. The template

```twig
{% trans_default_domain 'tictactoe' %}
<div class="mx-auto w-fit" data-controller="optimistic">
    {% include 'components/board.html.twig' with {board: view.board} %}
</div>
```

That's the whole template. No JavaScript, no hand-rolled cell markup - the
board component renders the form cells with `optimistic#insert` templates
(see [optimism.md](components/optimism.md)). Games with movable pieces add
the `dragdrop--piece-move` controller and a moves map instead.

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

### Cards & zones: a deck, hands, piles

```php
$table = $state->table = new Table();
$deck = DeckFactory::deck52();               // shuffled list<PlayingCard>

$table->add(new Zone('hand:'.$player->id, $player->id, ZoneVisibility::Owner))
    ->push(...array_splice($deck, 0, 5));    // deal 5
$table->add(new Zone('stock', visibility: ZoneVisibility::Hidden))->push(...$deck);

$drawn = Piles::draw($table->zone('stock')->items, $table->zone('discard')->items, 1);
$view = CardPresenter::views($table->hand($playerId)->items);
```

Zones/piles/table areas: [zones-and-tables.md](components/zones-and-tables.md).
Cards, decks (incl. `CustomCard` number decks) and the three drag-and-drop
shapes: [cards.md](components/cards.md).

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

### Settings: a host-configurable rule variant

```php
public function settings(): array
{
    return [
        new GameSetting(key: 'forcedCapture', labelKey: 'setting.checkers.forced_capture', type: GameSettingType::Bool, default: false),
    ];
}
```

That's it - the waiting room grows a settings form for it automatically
(host-only, before the game starts), and the resolved value shows up as
`$state->data['settings']['forcedCapture']`. See
[engine-and-state.md](components/engine-and-state.md) for the `Int`/`Enum`
variants and how round-replay reuses the same settings.

## Reference

| Doc | Covers |
|---|---|
| [engine-and-state.md](components/engine-and-state.md) | `GameEngineInterface`/`AbstractGameDefinition`, `GameState`, `PlayerViews`, autoplay, host-configurable settings, round replay, translations |
| [tokens-and-boards.md](components/tokens-and-boards.md) | `Token` + `token.html.twig`, `Board`/`BoardViews`/`board.html.twig`, `Path`, `Gravity`, `MoveMap`, the `piece-move` controller |
| [zones-and-tables.md](components/zones-and-tables.md) | `Zone`/`Table`/`ZoneVisibility`, `pile.html.twig`, `table_area.html.twig` |
| [cards.md](components/cards.md) | `PlayingCard`/`CustomCard`/`DeckFactory`/`Piles`/presenters, `card.html.twig`, `cards`/`group_swap`/`zone_drop`/`piece-move` |
| [chips.md](components/chips.md) | `Chip`/`ChipViews`, `chip.html.twig`, `chip_stack.html.twig` |
| [dice.md](components/dice.md) | `Dice` (incl. custom faces), `die.html.twig`, `die_roller.html.twig`, `dice_tray.html.twig` |
| [score-sheet.md](components/score-sheet.md) | `score_sheet.html.twig` - category × player scoring grids |
| [optimism.md](components/optimism.md) | `assets/optimistic.js` defaults, the `optimistic` primitives, `choice-dialog` |
| [ui-kit.md](components/ui-kit.md) | `.btn` classes, `player_banner`, FLIP/exit-ghost `key`/`flip`/`exit` conventions |

Also read [development_policies.md](development_policies.md) - the
reuse-first rules every change must follow.
