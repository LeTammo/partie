# Architecture

How the app is wired together. For how to build a game on top of it, see
[ADDING_A_GAME.md](ADDING_A_GAME.md) and [components/](components/) instead.

## Directory layout

```
src/Game/
├── Core/                     Reusable across every game - see docs/components/
│   ├── Model/                Board, Token, Dice, Player, GameState, Lobby, GameStatus, ...
│   ├── Card/                 PlayingCard, DeckFactory, Piles, CardPresenter, Rank, Suit
│   ├── View/                 BoardViews, PlayerViews (render-data helpers for GameRenderers)
│   ├── Service/              GameEngineInterface, AbstractGameDefinition, AutoPlayingEngineInterface,
│   │                         GameRegistry, LobbyManager, GameBroadcaster, PlayerSession
│   └── Exception/            GameException, InvalidMoveException, LobbyNotFoundException
└── Games/
    ├── TicTacToe/, ConnectFour/, Checkers/   grid games
    ├── MauMau/, Rummy/, Koepknack/           card games
    ├── Blackjack/                            card game, dealer auto-plays
    └── Yahtzee/                              dice game
    (each: GameDefinition + GameRules + GameRenderer)
```

```
src/Controller/
├── HomeController.php     GET  /                      Dashboard (game picker, join form)
├── LobbyController.php    POST /lobby/create          Create lobby, become host
│                          POST /lobby/join            Join via invite code
│                          GET  /lobby/{code}          Waiting room or game view
│                          POST /lobby/{code}/start    Host starts the match
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
- **Identity**: `PlayerSession` stores `lobby_player_{CODE} => playerId` in
  the Symfony session. A visitor without a seat sees the lobby as a
  spectator and can grab a seat while it's still `waiting`.
- **Security**: every POST is CSRF-protected, moves are validated
  server-side, and a player can never move on another player's behalf.
