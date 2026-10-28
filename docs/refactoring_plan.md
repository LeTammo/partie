# Component Refactoring Plan

Goal: every game in this repository is *only* rules + a thin renderer + a template
composed of shared core components. This plan inventories where the current
implementation falls short of that, decides the open architecture questions
(Board vs. Table, RowFour, DicePoker's choice board), defines the target
component set (requirements A–H), and lays out a phased migration allowed
to rewrite games completely.

---

## 1. Where we are

### 1.1 What is already good (keep as-is)

| Piece | Verdict |
|---|---|
| `GameEngineInterface` / `AbstractGameDefinition` / `GameRegistry` / autotagging | Solid. Untouched by this refactor. |
| `PlayingCard`, `Rank`, `Suit`, `DeckFactory`, `Piles`, `CardPresenter` | Good (requirement C is essentially done). Extended, not replaced. |
| `GameSetting` / `GameSettingsResolver` / generic lobby settings form | Good. |
| Optimistic-UI layer (`optimistic.js`, `optimistic` controller, `choice-dialog`, `flip.js`, `animation.js`) | Good foundation; all new controllers build on it. |
| `dragdrop--zone-drop` (MauMau) and `dragdrop--group-swap` (Koepknack) | Keep. Their state machines ("drop submits the source's own form" / "swap two radio-slots") genuinely differ from map-driven piece movement. |
| Mercure refresh-broadcast + morph render loop | Untouched. |

### 1.2 Where the philosophy is violated

**Tokens are rendered five different ways.** There is a `Token` model but **no
`components/token.html.twig`**. Each game hand-rolls its piece markup:

- RowFour: inline `<span>` disc + inner dot (`templates/game/rowfour/board.html.twig`, twice — once in the grid, once in the optimistic `<template>`)
- Checkers: its own disc + crown-icon king variant
- Ludo: a private `pawn` macro with *three* color areas (500/700/300 tiers) — the closest thing to the desired token design, locked inside one game
- TicTacToe: raw text `✕`/`◯`
- The `Token` model itself is too small: only `outerColor`/`innerColor`, no third area, no ring/shadow/symbol config.

**Ludo bypasses the core entirely.** It uses no `Board`, no `Token`; pawn state
is bare progress integers, and `GameRenderer` hardcodes a 40-cell ring path,
home lanes, base slots and CSS-grid coordinates (245 lines, all single-use). It
ships its own Stimulus controller (`ludo_controller.js`) that re-implements the
hover/drop mechanics that `dragdrop.js` helpers already provide.

**Solitaire invented a parallel zone system.** Its string-keyed zones
(`tableau:3:2`, `foundation:hearts`, `waste`) plus a legal-moves map plus a
custom controller (`solitaire_controller.js`) duplicate the pick/drag/drop/paint
state machine of `dragdrop--grid-move` almost 1:1 — the only real additions are
"picking a card picks the run stacked on it" and "a foundation shows only its top
card". Ironically this zone-key + moves-map idea is *the right design*; it just
lives in the wrong layer.

**Card-table layouts are copy-pasted.** MauMau, Rummy, Koepknack, Blackjack, and
Solitaire each hand-roll the same building blocks: a face-down draw pile with a
count label, a discard/top-card spot, a labeled `bg-cream` area, a hand row, an
empty-slot placeholder (dashed border). Same markup, five times, slightly
different every time.

**Player summaries are rendered three ways.** `player_chip.html.twig` (MauMau,
Rummy), a hand-rolled centered tile (Koepknack), a hand-rolled seat header
(Blackjack). The ui-kit doc even blesses this — that guidance is reversed by
this plan: one configurable banner component with layout variants.

**Chips don't exist as a component.** Blackjack shows `chip.svg.twig` + a number.

**DicePoker's scorecard is single-use.** The rows × players choice grid with
"scored / clickable-potential / empty" cells is generic for any roll-and-score
game but lives entirely in `templates/game/dicepoker/table.html.twig`.

**Dead code:** `Core\Model\Card` (segmented card) and `GameState::$cards` are
referenced by no game. `TokenShape` is set by three games but never read by any
template.

**No tests.** `app/tests/` contains only `bootstrap.php`. A rewrite-heavy
refactor without a behavioral safety net is how rules regress silently.

---

## 2. Architecture decisions

### 2.1 Are Boards and Tables the same thing?

**Answer: same contract, two layout components.**

The unifying concept is the **Zone**: a named location, identified by an opaque
string key, that holds pieces (tokens, cards, chips), knows its owner and
visibility, and can be a legal-move source or destination.

- A **Table** is a set of zones laid out freely (piles, hands, melds, seats,
  a middle row).
- A **Board** is a set of zones laid out on an x/y grid (each cell is a zone
  with key `cell:x:y`), optionally with named paths across cells.

What they *share* — and this is the part that matters:

1. **The move contract**: `moves: array<string sourceKey, list<string destKey>>`,
   built by the renderer, is the single way a game declares "this piece may go
   there". Checkers' `"3:4" => [{toX,toY},…]`, Solitaire's
   `"tableau:3:2" => ["foundation:hearts"]` and Ludo's implicit target cells are
   all the same idea with three ad-hoc encodings today.
2. **One interaction controller** (`piece-move`, §3.3) consumes that map for
   click-to-move, drag-and-drop, highlight painting, and optimistic gliding —
   regardless of whether the zones are grid cells or card piles.
3. **The same piece components** (token, card, chip) render inside both.

Forcing one literal Twig component over both would mean a config union of
unrelated concerns (grid geometry vs. free layout), so: two layout components
(`board.html.twig`, `table_area.html.twig`), one shared interaction/zone layer
underneath.

### 2.2 Where does RowFour fit?

RowFour **is a grid board plus one extra interaction mode: gravity drop**.
The board is a standard `Board`; the only special part is that the *drop target
is a column*, and the piece lands in the lowest empty cell. That becomes a core
feature, not a custom game:

- Core rule helper: `Gravity::dropRow(Board $board, int $column)` (today's
  `RowFour\GameRules::dropRow()`, moved to core — any "drop into a rack"
  game needs it).
- Board component option `drop: 'column'`: renders the per-column drop buttons
  above the grid and wires `optimistic#insert` with `where: last-empty`
  (both already exist).

So no game with a rack ever writes this again. RowFour keeps only its
win-detection rules.

### 2.3 DicePoker's choice board

Becomes core: `components/score_sheet.html.twig` + a `ScoreSheetViews` helper.
Generic shape: rows (category key, hint key) × players (cell = scored value |
clickable potential | empty), plus optional subtotal/bonus rows and a totals
footer. DicePoker configures it; a future variant or custom
scoring game reuses it. Same treatment as the gravity rack: *even single-use
mechanics get built as configurable core components* so the next game can pick
them up.

### 2.4 Where interaction config lives

Requirement A/B asks for tokens/cards with configurable events (drag, click,
double-click, and what happens). Decision: **models stay dumb and serializable;
interactivity is declared in the view model** built by the renderer:

```php
// renderer output, per piece or zone
'interact' => [
    'source' => 'cell:2:3',        // this piece can be picked/dragged (its key in view.moves)
    'click'  => ['action' => 'move', 'pawn' => 2],  // or a form payload for plain click games
    'dblclick' => ['action' => 'autoplace'],        // optional
]
```

The Twig components translate this into `data-*` attributes; the `piece-move`
controller (or a plain form) does the rest. The backend stays the single
authority on what is legal (`applyMove` re-validates everything) — the
interaction config only mirrors legality for UX, exactly like today's moves map.

---

## 3. Target component inventory

### 3.1 Backend (`src/Game/Core/`)

New namespace `Core/Zone/` for the shared zone layer; `Core/Model/` grows.

| Component | Requirement | Spec |
|---|---|---|
| `Model\Token` (extend) | A | `ownerId`, `shape` (round/square), **three color areas**: `outerColor` (100%→92%), `middleColor` (92%→82%), `centerColor` (rest) — boundaries overridable; `ring: ?string` (highlight color), `shadow: bool`, `symbol: ?string` (char or icon name — covers Checkers' crown, TicTacToe's ✕/◯), `variant` (free-form game state, kept). |
| `Model\Chip` (new) | G | `color`, `label: ?string` (char/symbol/amount in the center), fixed edge design. Plus `ChipViews::stack(int $amount): list<Chip>` to break an amount into denominations. |
| `Model\Dice` (extend) | D | `faces: list<int\|string>` (default `[1..6]`) — int faces render pips, string faces render chars/symbols. `roll()`/`toggleLock()` unchanged. |
| `Zone\Zone` (new) | E, F | `key` (opaque string), `labelKey`, `ownerId: ?string`, `visibility` (`all` / `owner` / `hidden`), `kind` (`stack`, `row`, `spread`, `slot`), contents (list of pieces). |
| `Zone\Table` (new) | E | Named collection of `Zone`s + text spots; `move($fromKey, $toKey, $count)`, `top($key)`, `count($key)`; owned zones (a player's hand/seat), hidden zones revealable at runtime (Koepknack's round-end reveal, Blackjack's hole card). Lives on `GameState` (replaces the ad-hoc `data['drawPile']` / `data['hands']` arrays in card games). |
| `Model\Board` (extend) | F | Keeps `width`/`height`/`place`/`move`/`tokens`. Adds: per-cell descriptors (`CellStyle`: shape round/square, fill color, border-only/invisible — covers TicTacToe's bordered empty cells, Ludo's white circles and colored start/home cells, Checkers' dark squares); cell zone keys (`cell:x:y`); off-grid auxiliary zones (bases) if a game needs slots that aren't cells. |
| `Zone\Path` (new) | F | Named ordered list of coordinates over a `Board` + per-seat rotation: `Path::ring($cells)`, `$path->at($progress, $seatOffset)`. Ludo's `RING_PATH`/`HOME_LANES` become data handed to core, not renderer internals. |
| `View\MoveMap` (new) | A, E, F | Tiny builder for the `sourceKey => [destKeys]` map + json encoding helper. One encoding for Checkers, Solitaire, Ludo and every future game. |
| `View\ScoreSheetViews` (new) | (DicePoker) | Builds rows × players cell matrix from a scorecard array + potential-score closure. |
| `Rules\Gravity` (new) | (C4) | `dropRow(Board, int $column): ?int`. |
| **Delete** `Model\Card`, `GameState::$cards` | — | Dead code. The card component's face-override block (§3.2) covers future custom card faces; re-introduce a model only when a game actually needs it. |

`GameState` gains `public ?Table $table = null;` next to `board` and `dice`.
Card games put hands/piles/melds there; game-specific scalars stay in `data`.

### 3.2 Twig components (`templates/components/`)

| Component | Requirement | Spec |
|---|---|---|
| `token.html.twig` (new) | A | Renders a `Token` view: three concentric colorable areas, optional ring + shadow, optional symbol, size via `class`. Replaces RowFour's inline discs, Checkers' piece markup, Ludo's `pawn` macro, TicTacToe's marks. Accepts `key`/`flip`/`exit` like the card component, and the `interact` attributes from §2.4. |
| `card.html.twig` (extend) | B | Adds: configurable back design (`backColor`, `backPattern`), size presets (`size: 'md'\|'sm'` instead of ad-hoc `class: 'h-16 w-11'`), optional face-override block (custom fronts for future games), same `interact` attribute support. Existing `key`/`flip`/`exit` contract unchanged. |
| `chip.html.twig` + `chip_stack.html.twig` (new) | G | One poker chip (color, center label); a stack rendering a `ChipViews::stack()` result with a total. |
| `die.html.twig` (extend) | D | `symbols: []` option — string faces render as characters instead of pip layouts. Optional `rollable: true` renders it inside its own roll form (single-click-to-roll, wired to `optimistic#pending` wobble) so Ludo-style single-die games stop hand-building the form. |
| `dice_tray.html.twig` (keep) | D | Already the "dice group" component. Gains `symbols` pass-through. |
| `pile.html.twig` (new) | C, E | Face-down/face-up pile: top card or dashed empty-slot placeholder, count label, optional draw-form wiring (`optimistic#ghost` toward a target zone). Replaces the copy-pasted stock/waste/discard markup in MauMau, Rummy, Solitaire. |
| `table_area.html.twig` (new) | E | A labeled zone container (`bg-cream` area): title/text spots, optional owner attribution + current-turn ring, `hidden`/`revealed` states, arbitrary content block. Composes into full tables for MauMau/Rummy/Koepknack/Blackjack/Solitaire. |
| `board.html.twig` (new) | F | Renders a `Board` view: grid CSS, per-cell style descriptors, tokens via `token.html.twig`, cell zone keys + `piece-move` wiring, optional `drop: 'column'` gravity header (§2.2), optional decorative layers (Ludo's backdrops/background dots as slots). |
| `score_sheet.html.twig` (new) | (DicePoker) | §2.3. |
| `player_banner.html.twig` (new) | H | Supersedes `player_chip.html.twig`. Variants: `inline` (dot + name + extras — today's chip), `tile` (centered two-line — Koepknack), `seat` (header row inside a player area — Blackjack). Slots for points, chip amounts, card counts, badges. `p.current` ring in all variants. |

### 3.3 JavaScript (`assets/controllers/`)

| Controller | Fate |
|---|---|
| `dragdrop/piece_move_controller.js` (new) | The one map-driven movement controller. Evolution of `grid_move`: **opaque string zone keys** instead of x/y ints; values: `moves` (map), optional `runs: true` (picking a card picks it + following siblings — Solitaire), optional `captureDistance` (Checkers' cosmetic fade; reads optional `data-x`/`data-y`), optional `clearTargetOnDrop` selector behavior (foundation shows only top card). Click-to-move + drag-and-drop + selected/target painting + optimistic FLIP move + hidden `from`/`to` form submit. |
| `dragdrop/grid_move_controller.js` | Deleted (absorbed into `piece_move`). |
| `solitaire_controller.js` | Deleted (it *is* `piece_move` minus generality). |
| `ludo_controller.js` | Deleted (Ludo pawns become `piece_move` sources; roll die via `die.html.twig` `rollable`). |
| `dragdrop/zone_drop_controller.js` | Kept — "drop submits the source's own form" (MauMau + choice-dialog interplay) is a different machine. |
| `dragdrop/group_swap_controller.js` | Kept — slot-swap is a different machine. |
| `cards_controller.js`, `optimistic_controller.js`, `choice_dialog_controller.js`, `autoplay_controller.js` | Kept unchanged. |

End state: **zero game-named controllers**. A game may only configure the shared
ones from its template.

---

## 4. Per-game migration

Ordered by how much they violate the philosophy today:

| Game | Work | Components after |
|---|---|---|
| **Ludo** | Full rewrite of state + renderer + template. State: pawns become `Token`s on a `Board(11,11)`; ring/home-lanes/bases become core `Path`s + cell styles declared in `createInitialState`. Renderer shrinks to: build cell descriptors via `BoardViews`, build `MoveMap` (`ring:17`, `home:2:1`, `base:0:3` keys), done. Template: `board.html.twig` + `token.html.twig` + rollable `die`. Delete `ludo_controller.js`. Rules (`GameRules`) survive nearly unchanged — they already operate on progress math. |
| **Solitaire** | Zones move into `Zone\Table` (`stock`, `waste`, `foundation:*`, `tableau:*` — keys survive!). Template recomposes from `pile`, `table_area`, `card`; `piece-move` with `runs: true` replaces `solitaire_controller.js`. Move/validation logic in `GameDefinition` largely survives, now speaking `Table::move()`. |
| **Checkers** | Switch `grid_move` → `piece_move` (`cell:x:y` keys), template grid → `board.html.twig`, piece markup → `token.html.twig` (king = `symbol: 'crown'`). Rules untouched. |
| **RowFour** | `dropRow` moves to `Rules\Gravity`; template → `board.html.twig` with `drop: 'column'`; discs → `token.html.twig` (also inside the optimistic `<template>`). Rules keep win detection only. |
| **TicTacToe** | Marks → `token.html.twig` (`symbol`-only tokens); grid → `board.html.twig` with border-only cell style. Stays `optimistic#insert` (no moves map needed — cell click = place). |
| **Blackjack** | Dealer area + seats → `table_area` + `player_banner(seat)`; chip counts → `chip`/`chip_stack`; bet buttons carry chip components. Dealer/settle auto-play untouched. |
| **Koepknack** | Middle/hand/reveal → `table_area` (reveal = a `hidden` zone flipped to revealed); player tiles → `player_banner(tile)`. `group_swap` interaction unchanged. |
| **MauMau** | Draw pile/discard → `pile` + `table_area`; players → `player_banner(inline)`. `zone_drop` + `choice-dialog` unchanged. |
| **Rummy** | Stock/discard → `pile`; melds → `table_area` per meld; players → `player_banner(inline)`. `cards` controller unchanged. |
| **DicePoker** | Scorecard → `score_sheet.html.twig` + `ScoreSheetViews`. Dice already shared. |

---

## 5. Phased execution

Each phase leaves the app fully playable; verify per phase with the checklist in
§5.8. Phases are sized to be one PR each.

### Phase 0 — Safety net + ground rules
1. **Engine tests.** Add PHPUnit tests per game against `GameDefinition` only
   (no HTTP, no Twig): `createInitialState` invariants + scripted move
   sequences incl. illegal moves + win/draw detection. These freeze the *rules*
   so phases 1–6 can rewrite everything else fearlessly. (Seed randomness by
   injecting/replaying: where needed, make deck/roll injectable via a small
   `RandomnessInterface` or by constructing states directly.)
2. See general policy described in `[development_policies.md](docs/development_policies.md)`
3. Delete dead code: `Core\Model\Card`, `GameState::$cards`.

### Phase 1 — Piece components (A, B, D, G)
1. Extend `Token` (three areas, ring, shadow, symbol); add `Chip`; extend
   `Dice` with `faces`.
2. New `token.html.twig`, `chip.html.twig`, `chip_stack.html.twig`; extend
   `card.html.twig` (back design, size presets) and `die.html.twig`
   (symbols, `rollable`).
3. Migrate the *pure-markup* consumers now (no interaction change):
   TicTacToe marks, RowFour discs, Checkers pieces, Blackjack chip display.

### Phase 2 — One movement controller
1. Write `dragdrop/piece_move_controller.js` (opaque keys, runs, capture fade,
   optimistic glide, from/to form).
2. Migrate Checkers (mechanical: `x:y` → `cell:x:y`), then Solitaire
   (`runs: true`), delete `grid_move_controller.js` + `solitaire_controller.js`.
3. Add `View\MoveMap`; both games' renderers emit it.

### Phase 3 — Zone/Table layer + card games (E)
1. Add `Zone\Zone`, `Zone\Table`, `GameState::$table`.
2. New `pile.html.twig`, `table_area.html.twig`, `player_banner.html.twig`
   (delete `player_chip.html.twig` after last consumer moves).
3. Migrate MauMau, Rummy, Koepknack, Blackjack, Solitaire state + templates.
   This is the biggest phase; do it game-by-game (one commit each), template
   diff should be mostly deletions.

### Phase 4 — Board layer + Ludo rewrite (F)
1. Extend `Board` (cell styles, zone keys, aux zones); add `Zone\Path`,
   `Rules\Gravity`; new `board.html.twig` with `drop: 'column'` mode.
2. Migrate TicTacToe, RowFour, Checkers templates onto `board.html.twig`.
3. Rewrite Ludo (state → tokens on board + paths; delete `ludo_controller.js`).

### Phase 5 — Score sheet (DicePoker)
`ScoreSheetViews` + `score_sheet.html.twig`; DicePoker template shrinks to
dice tray + score sheet + totals config.

### Phase 6 — Docs & cleanup
1. Rewrite `docs/components/`: new pages `zones-and-tables.md`, `chips.md`,
   `score-sheet.md`; update `tokens-and-boards.md`, `cards.md`, `dice.md`,
   `ui-kit.md` (banner replaces chip; reverse the "differently-shaped player
   summaries stay custom markup" guidance). Update `adding_a_game.md`.
2. README: 9 → 10 games, mention Solitaire.
3. `grep` gate: no game template may contain hand-rolled piece markup
   (`rounded-full` discs, card rectangles) or a game-named controller.

### 5.8 Per-phase verification
```bash
cd app
php bin/phpunit                                  # phase 0 onward
php -l src/Game/... && php bin/console lint:twig templates && php bin/console lint:yaml translations
php bin/console debug:container --tag=app.game   # still 10 games
php bin/console debug:asset-map                  # controllers resolve
```
Plus a manual pass per touched game: min & max player counts, one illegal move
(must revert cleanly via FLIP ghost), one full game to the win screen.

---

## 6. Risks & constraints

- **Serialized state compatibility.** Lobbies are PHP-serialized into the
  filesystem cache (12h TTL). Every phase that changes `GameState`/model shapes
  breaks *running* matches on deploy. Accepted: deploy phases individually, run
  `php bin/console app:lobby:cleanup` after deploying a state-shape change.
  Don't write BC shims — the TTL makes them dead code within a day.
- **Rules must not drift.** That's what Phase 0's tests are for. Any phase that
  touches a `GameRules` file beyond mechanical moves must keep its tests green
  without editing expectations.
- **Over-generalization.** The zone layer must stay a *dumb container*; turn
  order, phases, legality all remain per-game (`applyMove`). If a component
  needs a `mode` flag that only one game would ever set, it probably wants a
  slot/block instead (see `table_area`'s content block).
- **Translation domains.** New shared components put their strings in
  `messages.{en,de}.yaml` (`token.*`, `chip.*`, `sheet.*`), never in a game
  domain — same rule the dice tray already follows.
