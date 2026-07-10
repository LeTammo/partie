# Architecture

How the app is wired together. For how to build a game on top of it, see
[adding_a_game.md](adding_a_game.md) and [components/...](components/) instead.

## Directory layout

```
src/Game/
├── Core/                     Reusable across every game - see docs/components/
│   ├── Model/                Board, Token, Dice, Player, GameState, Lobby, GameStatus,
│   │                         GameSetting, GameSettingType, ...
│   ├── Card/                 PlayingCard, DeckFactory, Piles, CardPresenter, Rank, Suit
│   ├── View/                 BoardViews, PlayerViews (render-data helpers for GameRenderers)
│   ├── Service/              GameEngineInterface, AbstractGameDefinition, AutoPlayingEngineInterface,
│   │                         GameSettingsResolver, GameRegistry, LobbyManager, GameBroadcaster, PlayerSession
│   └── Exception/            GameException, InvalidMoveException, LobbyNotFoundException
└── Games/
    ├── TicTacToe/, ConnectFour/, Checkers/   grid games
    ├── Ludo/                                 track/race game, custom (non-grid) board
    ├── MauMau/, Rummy/, Koepknack/           card games
    ├── Blackjack/                            card game, dealer auto-plays
    └── Yahtzee/                              dice game
    (each: GameDefinition + GameRules + GameRenderer)
```

```
src/Controller/
├── HomeController.php     GET  /                      Dashboard (game picker, open lobbies, join form)
│                          POST /nickname              Update the session's default nickname
├── LobbyController.php    POST /lobby/create          Create lobby, become host
│                          POST /lobby/join            Join via invite code
│                          GET  /lobby/{code}          Waiting room or game view
│                          POST /lobby/{code}/rename   Rename the seated player, broadcast
│                          POST /lobby/{code}/heartbeat Keep-alive ping, updates Lobby::$lastSeen
│                          POST /lobby/{code}/settings Host updates game settings (before start)
│                          POST /lobby/{code}/start    Host starts the match
│                          POST /lobby/{code}/replay   Host starts another round, same players/settings
├── GameController.php     POST /lobby/{code}/move     Apply a move, save, broadcast
│                          POST /lobby/{code}/tick     Advance an auto-playing game one step
└── LocaleController.php   GET  /locale/{locale}       Switch en/de
```

## Request / sync flow

1. A player submits a move form (plain `<form method="post">`, enhanced by Turbo Drive).
2. `GameController::move()` loads the `Lobby` from the cache, resolves the acting
   `Player` from the session, and calls `$game->applyMove($state, $playerId, $payload)`.
3. The game validates the move, mutates its `GameState`, logs an event, and
   advances the turn or finishes the game.
4. The lobby is saved back to the cache, and `GameBroadcaster` publishes
   `<turbo-stream action="refresh">` to the Mercure topic `lobby/{CODE}`.
5. Every subscribed browser re-fetches the page with **its own session**, and
   Turbo **morphs** the DOM instead of replacing it.

Broadcasting a tiny "refresh" signal instead of rendered HTML keeps the
stream viewer-agnostic while every client still gets its own personalized
render (own controls, own turn indicator, own legal moves). This is also
why every user action is optimistic by default on the frontend (see
[components/optimism.md](components/optimism.md)) - the round trip above
takes a moment, so the UI reacts immediately and the morph just confirms it.

## State & sessions

- **Lobby storage**: `LobbyManager` uses a dedicated `FilesystemAdapter` cache
  pool (namespace `lobbies`, TTL 12h). The whole `Lobby` object graph
  (players + `GameState`, boards, tokens, dice, hands) is PHP-serialized.
  A lobby also carries the host's chosen game settings and a per-player
  round-win tally, both of which outlive any single `GameState` - see
  [components/engine-and-state.md](components/engine-and-state.md) for how
  settings and round replay work.
- **Identity**: `PlayerSession` stores `lobby_player_{CODE} => playerId` in
  the Symfony session. A visitor without a seat sees the lobby as a
  spectator and can grab a seat while it's still `waiting`.
- **Security**: every POST is CSRF-protected, moves are validated
  server-side, and a player can never move on another player's behalf.

## Lobby cleanup

Every seated player's browser pings `POST /lobby/{code}/heartbeat` every 25s
(the `heartbeat` Stimulus controller, wired in `lobby/show.html.twig` whenever
`me is not null`), which stamps `Lobby::$lastSeen[playerId] = time()`.
`LobbyManager::pruneStale()` deletes a lobby once *every* player's last-seen
timestamp is older than its threshold - 90s for a `waiting` lobby (nobody left
to start it), 5 minutes for a `running`/`finished` one (a match nobody's
watching). It runs opportunistically on every `listOpen()` call (the
dashboard's open-lobbies list), and is also exposed as
`php bin/console app:lobby:cleanup` for a real cron/scheduled task if you want
pruning to happen even when nobody's visiting the dashboard.
