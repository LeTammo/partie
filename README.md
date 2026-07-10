# Partie <> Multiplayer Board Games

A lightweight, modular multiplayer board game web app built with **Symfony 8.1** and **PHP 8.3+**.

---

## Table of contents

1. [Setup](#setup)
2. [Adding a new game](#adding-a-new-game)
3. [Architecture](#architecture)
4. [Frontend](#frontend)

## Setup

- **Local development** - [docs/DEVELOPMENT.md](./docs/DEVELOPMENT.md)
- **Production deployment** - [docs/DEPLOYMENT.md](./docs/DEPLOYMENT.md)

---

## Adding a new game

Only three files under `src/Game/Games/<YourGame>/` plus one template are needed. 
The dashboard picks the game up automatically.


1. **Create the module**

   ```
   src/Game/Games/<YourGame>/
   ├── GameDefinition.php   implements GameEngineInterface
   ├── GameRules.php        pure move validation / win detection
   └── GameRenderer.php     turns GameState into render-ready arrays
   ```

   `GameDefinition` (autoconfigured via the `#[AutoconfigureTag('app.game')]` on the
   interface) provides metadata (`getId()`, `getName()`, `getIcon()`, min/max players),
   builds the initial `GameState` and applies moves:

   ```php
   public function createInitialState(array $players): GameState
   {
       $state = new GameState($this->getId(), $players, new Board(8, 8));
       // place initial Tokens, fill $state->data with game specifics
       return $state;
   }

   public function applyMove(GameState $state, string $playerId, array $payload): void
   {
       if (!$state->isPlayersTurn($playerId)) {
           throw new InvalidMoveException('It is not your turn.');
       }
       // validate via GameRules, mutate board/dice/data,
       // $state->logEvent(...), then $state->advanceTurn() or $state->finish($winnerId)
   }
   ```

2. **Create the board template** at the path returned by `getTemplate()`
   (e.g. `templates/game/reversi/board.html.twig`). It receives `lobby`, `state`, `me`
   (the viewing `Player` or `null` for spectators) and `view` (your renderer's output).
   Moves are plain forms posting to `app_game_move`:

   ```twig
   <form method="post" action="{{ path('app_game_move', {code: lobby.code}) }}">
       <input type="hidden" name="_token" value="{{ csrf_token('game') }}">
       <input type="hidden" name="x" value="{{ cell.x }}">
       <button type="submit">…</button>
   </form>
   ```

   Everything in the payload except `_token` is passed to `applyMove()` as `$payload`.

3. If the game needs client-side interaction (like the two-tap picker in Checkers), drop a Stimulus
   controller into `assets/controllers/`. It is auto-registered by the Stimulus loader.

**Building blocks you can reuse:** `Board` (any grid size, gravity/adjacency helpers are
trivial on top), `Token` (shape + two-tone colors + `variant` for promotions), `Dice`
(lockable, any face count), `Card` (segment map for values/symbols), and free-form
`GameState::$data` for anything else (scorecards, direction maps, phase flags …).

---

## Architecture

```
src/Game/
├── Core/
│   ├── Exception/            GameException, InvalidMoveException, LobbyNotFoundException
│   ├── Model/                Reusable, cache-serializable domain objects
│   │   ├── Board.php         Coordinate grid (x, y) holding Tokens
│   │   ├── Token.php         Piece: shape (round/square/custom), outer/inner color, variant ("king", "x", ...)
│   │   ├── Dice.php          Die: value, maxFaces, locked (for turn-based holding)
│   │   ├── Card.php          Card with named segments (center, borders, corners, ...)
│   │   ├── Player.php        Session-based player: id, nickname, color, seat
│   │   ├── GameState.php     Players, turn index, board/dice/cards, log, winner, game data
│   │   ├── Lobby.php         Invite code, host, players, status, embedded GameState
│   │   └── GameStatus.php    waiting | running | finished
│   └── Service/
│       ├── GameEngineInterface.php   Contract for game modules (tagged `app.game`)
│       ├── GameRegistry.php          Collects all games via #[AutowireIterator('app.game')]
│       ├── LobbyManager.php          Create/join/start/save lobbies (FilesystemAdapter cache)
│       ├── GameBroadcaster.php       Publishes Turbo Stream updates through Mercure
│       └── PlayerSession.php         Maps browser session <-> Player per lobby
└── Games/
    ├── Checkers/    GameDefinition + GameRules + GameRenderer
    ├── .../          "
    ├── .../          "
```

```
src/Controller/
├── HomeController.php    GET  /                      dashboard (game picker, join form)
├── LobbyController.php   POST /lobby/create          create lobby, become host
│                         POST /lobby/join            join via invite code
│                         GET  /lobby/{code}          waiting room or game view
│                         POST /lobby/{code}/start    host starts the match
└── GameController.php    POST /lobby/{code}/move     apply a move, save, broadcast
```

### Request / sync flow

1. A player submits a move form (plain `<form method="post">`, enhanced by Turbo Drive).
2. `GameController::move()` loads the `Lobby` from the cache, resolves the acting `Player`
   from the session and delegates to the game module: `$game->applyMove($state, $playerId, $payload)`.
3. The module validates the move (`GameRules`), mutates the `GameState`, appends log entries,
   advances the turn or finishes the game.
4. The lobby is saved back to the cache and `GameBroadcaster` publishes
   `<turbo-stream action="refresh">` to the Mercure topic `lobby/{CODE}`.
5. Every subscribed browser (via `{{ turbo_stream_listen(topic) }}` on the lobby page)
   re-fetches the page with **its own session** and Turbo **morphs** the DOM
   (`<meta name="turbo-refresh-method" content="morph">` in `base.html.twig`).
   The acting player is updated by the normal redirect response.

Broadcasting a tiny "refresh" signal instead of rendered HTML keeps the stream
viewer-agnostic while every client still gets a personalized render (own controls,
own turn indicator, own legal moves).

### State & sessions

- **Lobby storage**: `LobbyManager` uses a dedicated `FilesystemAdapter` pool
  (namespace `lobbies`, TTL 12 h). The whole `Lobby` object graph (players + `GameState`,
  boards, tokens, dice, scorecards) is PHP-serialized.
- **Identity**: `PlayerSession` stores `lobby_player_{CODE} => playerId` in the Symfony
  session. Visitors without a seat see the lobby as spectators and can grab a seat while
  the lobby is still `waiting`.
- **Security**: every POST is CSRF-protected (`csrf_token('lobby')` / `csrf_token('game')`),
  moves are validated server-side, and it is impossible to move for another player.

---

## Frontend

- **AssetMapper + importmap** (`importmap.php`). JS consists of Turbo,
  Stimulus and one small `checkers_controller.js`.
- **Tailwind CSS v4** via `symfonycasts/tailwind-bundle` (standalone binary, no node).
  The pastel theme (warm grays, soft blue, sage, pale terracotta), the Nunito font and
  soft shadows are defined as design tokens in `assets/styles/app.css` under `@theme`
  (`bg-cream`, `text-warmgray-800`, `bg-sage-100`, `shadow-soft`, …).
- **Turbo**: forms and navigation are intercepted by Turbo Drive; remote updates arrive as
  Turbo Streams over Mercure (see flow above).

For local development see
[docs/DEVELOPMENT.md](./docs/DEVELOPMENT.md#useful-commands).  
For production deployment and reverse-proxy setups see
[docs/DEPLOYMENT.md](./docs/DEPLOYMENT.md).
