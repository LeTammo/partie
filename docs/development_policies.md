# Development policies

A important architectural rule of this repository: 
everything lives in a shared core of reusable components; a game is
only its rules plus configuration of those components.

## The reuse-first policy

1. **Reuse first.** Before writing any markup, model, or JavaScript, check what
   already exists:
   - Backend components: `app/src/Game/Core/` (models, card/deck helpers, zone
     and view helpers, engine services)
   - Twig components: `app/templates/components/`
   - Stimulus controllers: `app/assets/controllers/` (shared, never game-named)
   - Main reference doc: `docs/adding_a_game.md` with a full walkthrough

2. **Extend, don't fork.** If a core component *almost* fits, grow it with a
   configurable, game-agnostic option in core — don't copy it into a game,
   don't write a per-game variant of it. If a shared controller or component
   seems to be missing something, that is a sign the core should grow, not that
   the game needs its own. Of course there are exceptions: 
   If a game has a truly unique interaction, that cannot be expressed generically.

3. **New mechanics become core components.** If a game genuinely needs
   something new (a new piece type, a new zone layout, a new interaction), build
   it as a configurable component in core + a `docs/components/` — even if it 
   only one game uses it for now. It must be designed vague/configurable enough 
   that a second game could adopt it without edits.

4. **Try to avoid:**
   - a game-named Stimulus controller (`<game>_controller.js`)
   - hand-rolled piece markup in a game template (discs, pawns, cards, chips — use always the shared components)
   - a parallel encoding for zones or legal moves (use the shared zone-key / moves-map contract)

5. **Documentation**: Don't write comments in the code (it should be written self-documenting).
   Each time you want to explain something, write it into the documentation files in `docs/`. 
   Documentation should start in an easy-to-read way, with lesser text and more 
    examples, but can be extended with more details in paragraphs after the initial feature introduction. 

## What a game consists of mainly

```
app/src/Game/Games/<Name>/GameDefinition.php   # engine interface + move handling
app/src/Game/Games/<Name>/GameRules.php        # game rules
app/src/Game/Games/<Name>/GameRenderer.php     # returns the view-model array
app/templates/game/<id>/*.html.twig            # composes as much core components as possible
app/translations/<id>.{en,de}.yaml             # game specific language files
```
