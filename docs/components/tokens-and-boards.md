# Tokens & boards

For any game where pieces sit on a grid: Tic-Tac-Toe, Connect Four,
Checkers.

## `Board` (`src/Game/Core/Model/Board.php`)

A 0-indexed `width` × `height` grid of `Token`s.

```php
$board = new Board(8, 8);

$board->place(2, 3, new Token(ownerId: $player->id, outerColor: '#a3b8a3'));
$board->get(2, 3);           // ?Token
$board->isEmpty(2, 3);       // bool
$board->move(2, 3, 3, 4);    // throws if there's nothing at 2,3
$board->remove(3, 4);        // returns the removed Token, or null
$board->tokens();            // list<{x, y, token}> - every occupied cell
$board->countTokensOf($player->id);
```

## `Token` (`src/Game/Core/Model/Token.php`)

A generic piece: `ownerId`, `shape` (`TokenShape::Round`/`Custom`),
`outerColor`, `innerColor`, and a free-form `variant` string for
game-specific state (`"x"`/`"o"` in Tic-Tac-Toe, `"king"` in Checkers -
`$token->promote('king')`). Every `Token` gets a random `id` for free.

## Rendering: `BoardViews::grid()`

```php
$grid = BoardViews::grid($board, static fn (int $x, int $y, ?Token $token): array => [
    'x' => $x,
    'y' => $y,
    'outer' => $token?->outerColor,
    'inner' => $token?->innerColor,
]);
```

Runs the `for $y` / `for $x` loop for you; your closure returns whatever a
cell needs to render - that's the only per-game part.

## Moving a piece: the `dragdrop--grid-move` type

Handles both tap-to-move and drag-and-drop with one controller
(`assets/controllers/dragdrop/grid_move_controller.js`), animates the move
optimistically, and fades a captured piece if you set `captureDistance`.

**The legal-moves map** is the contract between your renderer and the
controller:

```php
// array<string, list<array{toX: int, toY: int}>>, keyed by "x:y"
$moves = [
    '2:3' => [['toX' => 3, 'toY' => 4], ['toX' => 1, 'toY' => 4]],
];
```

Build it while you compute legal moves for the viewer (see
`Checkers\GameRenderer` for a full example with capture rules), then pass it
to the template as `view.moves`.

**Template wiring:**

```twig
<div data-controller="dragdrop--grid-move"
     data-dragdrop--grid-move-moves-value="{{ view.moves|json_encode|e('html_attr') }}"
     data-dragdrop--grid-move-capture-distance-value="2">

    {# a hidden form the controller fills in and submits #}
    <form data-dragdrop--grid-move-target="form" class="hidden">
        <input type="hidden" name="fromX" data-dragdrop--grid-move-target="fromX">
        <input type="hidden" name="fromY" data-dragdrop--grid-move-target="fromY">
        <input type="hidden" name="toX" data-dragdrop--grid-move-target="toX">
        <input type="hidden" name="toY" data-dragdrop--grid-move-target="toY">
    </form>

    {% for row in view.grid %}
        {% for cell in row %}
            <button data-cell data-x="{{ cell.x }}" data-y="{{ cell.y }}"
                    data-action="click->dragdrop--grid-move#pick dragover->dragdrop--grid-move#dragOver drop->dragdrop--grid-move#drop">
                {% if cell.outer %}
                    <span data-flip-id="piece-{{ cell.tokenId }}"
                          {% if cell.mine %}
                              draggable="true" data-x="{{ cell.x }}" data-y="{{ cell.y }}"
                              data-action="dragstart->dragdrop--grid-move#dragStart dragend->dragdrop--grid-move#dragEnd"
                          {% endif %}
                          style="background-color: {{ cell.outer }}"></span>
                {% endif %}
            </button>
        {% endfor %}
    {% endfor %}
</div>
```

- Every cell: `data-cell data-x data-y` + the click/dragover/drop actions.
- A cell's piece (if any): `data-flip-id` so it can glide; only *your own*
  draggable pieces get `draggable="true"` + the dragstart/dragend actions.
- `captureDistanceValue` (optional): if set, moving exactly that many
  columns fades out whatever piece sits at the midpoint (Checkers' jump
  capture). Omit it for games with no capture-by-jump rule.
- Highlighting: `cell-selected` (the picked cell) / `cell-target` (its
  legal destinations) are painted automatically from the moves map - style
  them in CSS, don't toggle them yourself. The class names are exported as
  `CELL_SELECTED_CLASS`/`CELL_TARGET_CLASS` from `assets/dragdrop.js` if you
  need them in JS.
- A rejected move reverts automatically via the FLIP exit-ghost mechanism
  (see [ui-kit.md](ui-kit.md)) - nothing to do on your end.

Don't write a custom controller for a grid game. If `grid_move` seems to be
missing something, that's a sign the core type should grow, not that your
game needs its own JS.
