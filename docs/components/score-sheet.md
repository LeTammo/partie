# Score sheet

For any "roll/draw, then pick a category to score" game: Yahtzee, future
Kniffel variants or Qwixx-likes.

## `components/score_sheet.html.twig`

Category rows × player columns. A cell is a filled score, a clickable
potential score (submits `action` + `category` as a move, optimistic via
`optimistic#replace`), or an empty dot. Include **without** `only`:

```twig
{% include 'components/score_sheet.html.twig' with {
    rows: view.rows,                  {# [{category, cells: [{score, potential}]}] #}
    players: view.players,            {# PlayerViews::build() - seat order #}
    domain: 'yahtzee',                {# translation domain for labels/hints #}
    labelPrefix: 'yahtzee.category.',
    hintPrefix: 'yahtzee.hint.',      {# optional tooltip keys #}
    canScore: view.canScore,
    inserts: {
        sixes: {title: 'yahtzee.upper_bonus'|trans, values: view.upperBonusValues},
    },
    totals: {title: 'yahtzee.total'|trans, values: view.totals},
} %}
```

- `rows[].cells` are in player seat order; `score` wins over `potential`.
- `inserts` renders an extra summary tile after a category's tile
  (Yahtzee's upper-bonus row after "sixes"). Values are pre-formatted
  strings, one per player.
- `totals` renders the footer bar.
- The score button's tooltip uses the shared `sheet.score_action` key in
  `messages.{en,de}.yaml`.

Your renderer builds `rows` from its scorecards - see Yahtzee's
`GameRenderer::buildView()` for the reference shape. The scorecard state
itself stays game-specific (`$state->data['scorecards']`).
