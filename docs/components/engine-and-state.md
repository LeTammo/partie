# Engine & state

The parts every game has regardless of what it's played with.

## `GameEngineInterface` / `AbstractGameDefinition`

A game is anything implementing `GameEngineInterface`
(`src/Game/Core/Service/GameEngineInterface.php`):

```php
interface GameEngineInterface
{
    public function getId(): string;                  // 'tictactoe' - also the translation domain
    public function getName(): string;                // translation key
    public function getDescription(): string;         // translation key
    public function getIcon(): string;                // templates/icons/{name}.svg.twig
    public function getMinPlayers(): int;
    public function getMaxPlayers(): int;
    public function settings(): array;                // list<GameSetting>, default [] via AbstractGameDefinition
    public function createInitialState(array $players, array $settings = []): GameState;
    public function applyMove(GameState $state, string $playerId, array $payload): void;
    public function getTemplate(): string;            // 'game/tictactoe/board.html.twig'
    public function buildView(GameState $state, ?string $viewerId): array;
}
```

It's tagged `#[AutoconfigureTag('app.game')]`, so **any class implementing
it is picked up automatically** - no service registration, no config file.
Verify it landed with `php bin/console debug:container --tag=app.game`.

Extend `AbstractGameDefinition` instead of implementing the interface
directly - it adds:

```php
$this->invalidMove('error.<id>.something', ['%param%' => $value]);
// throws InvalidMoveException with domain: $this->getId() already filled in

$this->intParam($payload, 'x');              // (int) $payload['x'] ?? -1, with a default you can override
$this->stringParam($payload, 'category');    // (string) $payload['category'] ?? ''
$this->intListParam($payload, 'cards');      // "1,3,4" -> [1, 3, 4] (a multi-select from the `cards` controller)

$this->setting($state, 'forcedCapture');     // reads $state->data['settings']['forcedCapture'], or null if unset
```

All concrete games are `final readonly class ... extends
AbstractGameDefinition`. Readonly-ness must match across the class
hierarchy, so `AbstractGameDefinition` is declared `abstract readonly`
too - don't drop `readonly` from either side.

## Host-configurable settings

A game declares whatever rule variants make sense by overriding `settings()`
(default: none, via `AbstractGameDefinition`):

```php
public function settings(): array
{
    return [
        new GameSetting(
            key: 'forcedCapture',
            labelKey: 'setting.checkers.forced_capture',
            type: GameSettingType::Bool,
            default: false,
        ),
    ];
}
```

`GameSetting` (`src/Game/Core/Model/GameSetting.php`) supports three types:

| Type | Extra fields | Example |
|---|---|---|
| `GameSettingType::Bool` | - | Checkers' `forcedCapture` |
| `GameSettingType::Int` | `min`, `max` | Blackjack's `startChips` (20-1000) |
| `GameSettingType::Enum` | `options` (`array<string, string>`, value-as-string => translation key) | Mau-Mau's `skipRank` (one card rank per option) |

The lobby's waiting room renders a settings form **generically** from
whatever `settings()` returns - `templates/lobby/_settings.html.twig` loops
over it and picks the right input type, so a new game's settings show up
with zero template work. Only the host sees the form, and it saves itself:
the `autosave` Stimulus controller (`data-action="change->autosave#save"`)
fires a background `POST /lobby/{code}/settings`
(→ `LobbyManager::updateSettings()`) the moment any field changes - no
submit button, nothing to remember to click before starting the game.

Submitted values are merged with defaults via `GameSettingsResolver::resolve()`
(missing/invalid input silently falls back to the setting's default - a
checkbox left unchecked always resolves correctly because the form pairs
every checkbox with a hidden `value="0"` fallback of the same name) and
stored on `Lobby::$settings`. `LobbyManager::startGame()` (and `playAgain()`,
see below) passes them into `createInitialState($players, $settings)`, and
the convention is to stash the whole resolved array on the state too, so
`applyMove()`/the renderer can read it back later:

```php
public function createInitialState(array $players, array $settings = []): GameState
{
    $state = new GameState($this->getId(), $players);
    $state->data['settings'] = $settings;
    // ...
    return $state;
}
```

From anywhere else in the game, read a resolved value with
`$this->setting($state, 'forcedCapture')` (falls back to `null`, so give it
an explicit `?? default` at the call site if the setting is new enough that
an in-flight `GameState` from before you added it might not have it yet).

## `GameState` (`src/Game/Core/Model/GameState.php`)

```php
$state->players;             // list<Player>, seat order
$state->currentTurnIndex;
$state->currentPlayer();     // Player
$state->board;               // ?Board - grid games
$state->dice;                // list<Dice> - dice games
$state->data;                // array<string, mixed> - everything else (hands, phases, scorecards...)
$state->status;              // GameStatus::Running | Finished

$state->isPlayersTurn($playerId);       // bool
$state->isViewersTurn($viewerId);       // same, but $viewerId may be null (a spectator) - use this one in renderers
$state->advanceTurn();                  // moves to the next seat
$state->finish($winnerId);              // null winnerId = draw
```

Log a player-visible event:

```php
$state->logGameEvent('log.<id>.played', ['%player%' => $player->nickname]);
// stamps domain: $this->getId() and a sequence number

$state->logEvent('log.won', ['%player%' => $player->nickname]); // cross-game generic keys already in messages.*.yaml
```

**The turn-end convention.** There's no shared turn/phase state machine on
purpose - every game's phases differ too much for one to fit all. Instead,
give each game one `private function endTurn(GameState $state)` that resets
its own per-turn flags and calls `$state->advanceTurn()`:

```php
private function endTurn(GameState $state): void
{
    $state->data['hasDrawn'] = false;
    $state->advanceTurn();
}
```

## `PlayerViews::build()`

Every multi-player renderer builds the same "one row per player" shape -
nickname, color, whose turn it is:

```php
$players = PlayerViews::build($state, static fn (Player $player): array => [
    'cardCount' => \count($state->data['hands'][$player->id]),
]);
// [{nickname, color, current, cardCount}, ...]
```

Your closure's return value wins over the base fields, so a game with
different "current" semantics (e.g. nobody's turn during a reveal phase)
can just return its own `current` key:

```php
$players = PlayerViews::build($state, static fn (Player $player): array => [
    'points' => $state->data['points'][$player->id],
    'current' => $running && 'playing' === $phase && $state->currentPlayer()->id === $player->id,
]);
```

## Auto-playing games: `AutoPlayingEngineInterface`

For a game whose state keeps advancing without a player acting (Blackjack's
dealer, a delayed round-end reveal):

```php
final readonly class GameDefinition extends AbstractGameDefinition implements AutoPlayingEngineInterface
{
    public function hasAutoStep(GameState $state): bool
    {
        return GameStatus::Running === $state->status && \in_array($state->data['phase'], ['dealer', 'settle'], true);
    }

    public function applyAutoStep(GameState $state): void
    {
        match ($state->data['phase']) {
            'dealer' => $this->dealerStep($state),
            'settle' => $this->settleStep($state),
        };
    }
}
```

Render an `autoplay` controller instance to drive it client-side:

```twig
{% if view.autoPending %}
    <div data-controller="autoplay"
         data-autoplay-url-value="{{ path('app_game_tick', {code: lobby.code}) }}"
         data-autoplay-token-value="{{ csrf_token('game') }}"
         data-autoplay-step-value="{{ view.autoStep }}"></div>
{% endif %}
```

It ticks the server one step at a time on a delay, so a multi-step sequence
(dealer reveals, then draws, then settles each hand) reads as a paced
animation instead of jumping straight to the end. A failed tick fetch is
swallowed on purpose - the next Mercure broadcast or another viewer's own
tick will retry the step anyway.

## Replaying a round

A finished lobby doesn't have to end - the same seated players can start
another round with the same settings. This is entirely handled by
`Lobby`/`LobbyManager`; a game doesn't opt in or implement anything extra.

`Lobby` carries two fields alongside `$state` for this:

```php
$lobby->round;       // int, starts at 1, incremented by playAgain()
$lobby->roundWins;   // array<string playerId, int>, tallied automatically
```

`GameController` tallies the just-finished round's winner the moment a
move (or auto-step) flips `$lobby->status` to `Finished`:

```php
if (GameStatus::Finished === $lobby->state->status) {
    $lobby->status = GameStatus::Finished;
    $this->lobbyManager->recordRoundResult($lobby); // $lobby->roundWins[$winnerId]++, no-op on a draw
}
```

The host's "Play another round" button posts to `POST /lobby/{code}/replay`,
which calls `LobbyManager::playAgain()`:

```php
public function playAgain(Lobby $lobby, string $playerId): void
{
    // host-only, requires GameStatus::Finished, throws GameException otherwise
    ++$lobby->round;
    $lobby->status = GameStatus::Running;
    $lobby->state = $game->createInitialState($lobby->players, $lobby->settings);
    // ...
}
```

Same players, same settings, brand-new `GameState` - nothing game-specific
to write. The lobby template shows the round number and rounds-won tally
automatically once `$lobby->round > 1` / `$lobby->roundWins` isn't empty.

## Translations

One domain per game: `translations/<id>.{en,de}.yaml`, where `<id>` is
`getId()`. Add a `rules:` key (multiline YAML, `|-`) - it's split on `\n`
and shown as a bullet list in the lobby's rules popover automatically. A
setting's `labelKey` (and an `Enum` setting's option labels) are just more
keys in this same file, conventionally prefixed `setting.<id>.*`.

Strings owned by a *shared component* (not your game) belong in
`messages.{en,de}.yaml` instead - see `card.*` and `dice.*` for the
existing examples, and [ui-kit.md](ui-kit.md).
