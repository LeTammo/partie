# Partie <> Multiplayer Board Games

Partie is a modular toolkit for creating multiplayer board and card games, built on **Symfony 8.1**.  
There is a shared core of reusable building components and adding a new game means basically only writing its rules.

There are currently 9 playable games:
- Tic-Tac-Toe
- Connect Four
- Checkers
- Mensch ärgere Dich nicht
- Mau-Mau
- Rummy
- Koepknack (31)
- Blackjack
- Yahtzee

Stack:
- Symfony 8.1 (with PHP 8.3)
- Mercure for real-time updates
- Tailwind CSS 4.0

---

## Table of contents

1. [Setup](#setup)
2. [Adding a new game](#adding-a-new-game)
3. [Architecture](#architecture)

---

## Setup

- Local development: [docs/development.md](docs/development.md)
- Production deployment: [docs/deployment.md](docs/deployment.md)

---

## Adding a new game

See [docs/adding_a_game.md](docs/adding_a_game.md) for a guided walkthrough that builds Tic-Tac-Toe from scratch.  
There are in-depth references for every reusable component in `docs/components/` like
- cards, 
- tokens & boards
- dice
- the engine/state API
- optimistic UI
- and the shared UI kit

---

## Architecture

A move is a form POST; the server validates it, updates the game's state, and broadcasts a "refresh" signal over 
Mercure so every player's browser re-fetches its own view and Turbo morphs the page in.  
There's no game-specific server code beyond `src/Game/Games/<Name>/`.  
**Optimistic UI**: every action gives instant feedback before the server responds.

See [docs/architecture.md](docs/architecture.md) for a more detailed overview, the request/sync flow, 
and how lobbies and sessions are stored.

---
