# Tokens & boards

For any game where pieces sit on a grid or track: Tic-Tac-Toe, Connect
Four, Checkers, Ludo.

## `Token` (`src/Game/Core/Model/Token.php`)

A generic piece with up to three concentric colorable areas plus an
optional symbol:

```php
new Token(
    ownerId: $player->id,
    outerColor: 'var(--color-softblue-500)',  // the disc
    middleColor: 'var(--color-softblue-700)', // optional band
    centerColor: 'var(--color-softblue-300)', // optional dot
    symbol: '✕',                              // optional char in the center
    variant: 'king',                          // free-form game state
);
$token->promote('king');   // mutate the variant later
```

Ring, shadow and interactivity are *view* concerns - configured on the
component at render time, never stored on the model.

## `components/token.html.twig`

The one token component - every piece renders through it:

```twig
{% include 'components/token.html.twig' with {
    outer: cell.outer,
    center: cell.inner, centerSize: 45,     {# disc + small dot (Four in a Row) #}
    icon: cell.king ? 'crown' : null,       {# icon center (Checkers king) #}
    overlayIcon: sacrifice ? 'x' : null,    {# hover overlay, needs 'group' in class #}
    ring: cell.selectable,                  {# highlight ring #}
    size: 'size-8 sm:size-11',
    flip: 'piece-' ~ cell.tokenId, exit: 'fade',
    attr: {'data-source': key, draggable: 'true', 'data-action': '...'},
} only %}
```

- Three areas: `outer` (full disc), `middle` (band, default 92%),
  `center` (dot, default 82%); override with `middleSize`/`centerSize`.
  Ludo's pawns use `middleSize: 84, centerSize: 60`.
- `shape: 'plain'` renders only the symbol - Tic-Tac-Toe's ✕/◯.
- `attr` carries the interaction wiring (drag actions, `data-source`).
- `key`/`flip`/`exit` work like on the card component.

## `Board` (`src/Game/Core/Model/Board.php`)

A 0-indexed `width` × `height` grid of `Token`s:

```php
$board = new Board(8, 8);
$board->place(2, 3, $token);
$board->get(2, 3); $board->isEmpty(2, 3);
$board->move(2, 3, 3, 4); $board->remove(3, 4);
$board->tokens(); $board->countTokensOf($player->id);
```

## `Path` (`src/Game/Core/Zone/Path.php`)

A named, ordered list of coordinates - a race track, home lane, or base
row. `seatStride` rotates it per seat (Ludo: seat S starts 10 squares
further along the ring):

```php
$ring = new Path('ring', $cells, seatStride: 10);
$ring->cellFor($seat, $progress);   // [x, y] for a seat's progress step
$ring->indexFor($seat, $progress);  // absolute ring index
```

A race game's state stays progress-based (`$state->data['pawns']`); the
Path turns progress into board cells at render time. See Ludo's
`GameRenderer`.

## `Gravity` (`src/Game/Core/Rules/Gravity.php`)

The drop rule for rack games:

```php
$y = Gravity::dropRow($board, $column);   // lowest empty row, or null when full
```

## Rendering: `components/board.html.twig`

One include renders the whole grid - cells, tokens, zone wiring, click-to-
place forms, decorative layers, and an optional gravity drop-row. Your
renderer builds a `board` descriptor; the template stays tiny:

```twig
<div class="mx-auto w-fit" data-controller="dragdrop--piece-move"
     data-dragdrop--piece-move-moves-value="{{ view.moves|json_encode|e('html_attr') }}">
    {% include 'components/board.html.twig' with {board: view.board} %}
</div>
```

The descriptor (see the parameter list at the top of the component, and
TicTacToe/RowFour/Checkers/Ludo renderers for full examples):

- `cols`/`rows`, `class`, `style`, `panelClass` - grid scaffolding.
- `cells` - in-order cell maps: `class`/`style` (fill colors, explicit
  `grid-row/column` for sparse boards), `key` (wires the cell as a
  piece-move zone), `token` (token component params), `tokens` (list of
  token params for a cell that holds more than one piece - renders a capped
  overlapping stack with a "+N" badge, e.g. Backgammon's points; `tokensMax`
  caps how many stack visibly, default 5), `form` (a click-to-place cell
  with an `optimistic#insert` template - Tic-Tac-Toe), `dot` (decorative),
  `attr`.
- `layers` - decorative divs behind the cells (Ludo's colored backdrops).
- `drop` - the gravity drop-row above the grid (Four in a Row); cells then
  carry `attr: {'data-col': x}` so the optimistic disc falls into the
  right column.

`BoardViews::grid()` still runs the x/y loop for you when you build cell
descriptors from a `Board`.

## Moving pieces: the `piece-move` controller

**The one map-driven movement controller** - grid cells, card piles and
track squares all use it. Zone keys are opaque strings; the legal-moves
map is the contract between renderer and controller:

```php
$moves = new MoveMap();
$moves->add(MoveMap::cellKey(2, 3), MoveMap::cellKey(3, 4), MoveMap::cellKey(1, 4));
// solitaire: $moves->add('tableau:0:1', 'tableau:4', 'foundation:hearts');
// ludo:      $moves->add('ring:6', 'ring:10');
'moves' => $moves->toArray(),
```

Template wiring (the board component does the zone side for you):

```twig
<div data-controller="dragdrop--piece-move"
     data-dragdrop--piece-move-moves-value="{{ view.moves|json_encode|e('html_attr') }}"
     data-dragdrop--piece-move-capture-distance-value="2"     {# Checkers' jump fade #}
     data-dragdrop--piece-move-auto-select-value="true"       {# preselect a lone mover #}
     data-dragdrop--piece-move-runs-value="true"              {# Solitaire card runs #}
     data-dragdrop--piece-move-free-drag-value="true"         {# any card may be picked up #}
     data-dragdrop--piece-move-drag-highlight-value="foundation:">  {# drag highlights only these zones #}

    <form data-dragdrop--piece-move-target="form" class="hidden" method="post" action="...">
        <input type="hidden" name="from" data-dragdrop--piece-move-target="from">
        <input type="hidden" name="to" data-dragdrop--piece-move-target="to">
    </form>
</div>
```

- **Zones** (drop targets): `data-dragdrop--piece-move-target="zone"
  data-zone="{key}"` + the `pickZone`/`dragOver`/`drop` actions. A zone
  with `data-mode="replace"` swaps its content on an optimistic drop (a
  foundation pile showing only its top card); `data-mode="none"` submits
  the move without touching the DOM at all - the piece stays where it is
  until the server render decides (Durak's card offers, where dropping a
  card on another player only *asks*).
- **Sources** (pickable pieces): `data-source="{key}"` + the drag actions;
  standalone card buttons also register as `target="source"` with the
  `pick` click action. A grid cell containing exactly one source picks it
  on click automatically - unless something else is already selected and
  this zone is a legal target for it, in which case the click completes
  that move instead (otherwise a zone that is both a one-piece source and
  a legal destination could never be dropped onto by click, only by drag).
- **Choices**: `choicesValue` (a list of keys) + a `choice` input target
  turn marked zones into one-click choice submits (Checkers' sacrifice).
- **Drag feel**: `freeDrag` lets every source be picked up even without a
  legal move (the drop just never lands); `dragHighlight` limits the
  target highlight during a drag to zones with the given key prefix.
  While dragging, the selection box is never painted - the piece under
  the cursor is feedback enough. Click-to-move always paints selection
  and all targets.
- The move submits `from`/`to` zone keys; parse coordinates back with
  `MoveMap::coordsOf($key)`.
- Selected/target highlighting uses the `cell-selected`/`cell-target` CSS
  classes automatically.
- **Rapid consecutive moves** (a free-for-all game like Durak, where one
  player can draw several cards back to back): the optimistic DOM move
  happens instantly on every click, but the actual network submissions are
  queued and sent one at a time, only firing the next once `turbo:submit-end`
  confirms the previous one landed. Two fetches launched back to back can
  otherwise arrive at the server out of order, getting the second rejected
  as impossible before the first one's effect even took hold - this is
  invisible to the player either way since the UI already reacted. A fresh
  `moves` render (the confirmation morph, or another player's action
  arriving via Mercure) never cancels an in-progress selection that is still
  valid - only a selection that the new map no longer lists gets cleared -
  so a fast pick-then-drop isn't silently dropped just because a morph
  happened to land in between. And while more of this client's own moves are
  still queued behind the one a morph confirms, that morph is skipped
  entirely - it only knows about the single request it answers, so applying
  it would visually snap the DOM back to "just that one happened" and then
  immediately re-advance once the next response lands. Only the last morph
  in a queued burst is ever allowed through, once every move in it has
  landed, reconciling everything in one clean step instead of flickering.
  And if a submission's own `turbo:submit-end` doesn't report a clean
  success - an overloaded server, a dropped connection - it is never
  retried: there is no way to tell from the client whether the server
  already executed that (possibly non-idempotent) move before failing to
  answer, so retrying could double it up (drawing two cards instead of
  one). Instead it forces a full `Turbo.visit` resync, trading a flicker
  for certainty that the client ends up wherever the server actually is.
- **Per-zone runs**: `runsValue` is the controller-wide default for "picking
  a source also picks its following DOM siblings", but a piece's own
  immediate parent can override it locally with `data-runs="true"` or
  `"false"` - useful when one controller instance mixes a stacked-pile zone
  (take a card, take whatever's stacked on top of it too) with plain
  single-card zones (a hand, a stock pile) that must never drag siblings
  along. Requires the pile's cards to be direct siblings under that parent,
  same as Solitaire's tableau columns.

`dragdrop--zone-drop` ("dropping submits the source's own form", Mau-Mau)
and `dragdrop--group-swap` (slot swapping, Koepknack) remain separate -
their state machines genuinely differ. Anything map-driven belongs in
`piece-move`; if it seems to be missing something, grow the controller,
don't write a game-specific one.
