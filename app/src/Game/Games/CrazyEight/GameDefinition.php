<?php

declare(strict_types=1);

namespace App\Game\Games\CrazyEight;

use App\Game\Core\Card\CustomCard;
use App\Game\Core\Card\Piles;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameSetting;
use App\Game\Core\Model\GameSettingType;
use App\Game\Core\Model\GameState;
use App\Game\Core\Service\AbstractGameDefinition;
use App\Game\Core\Zone\Table;
use App\Game\Core\Zone\Zone;
use App\Game\Core\Zone\ZoneVisibility;

final readonly class GameDefinition extends AbstractGameDefinition
{
    private const array COLORS = ['red', 'yellow', 'green', 'blue'];

    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'crazyeight';
    }

    public function getName(): string
    {
        return 'game.crazyeight.name';
    }

    public function getDescription(): string
    {
        return 'game.crazyeight.description';
    }

    public function getIcon(): string
    {
        return 'games/crazyeight';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 6;
    }

    public function settings(): array
    {
        return [
            new GameSetting(
                key: 'stackDraw2',
                labelKey: 'setting.crazyeight.stack_draw2',
                type: GameSettingType::Bool,
                default: true,
            ),
            new GameSetting(
                key: 'startHandSize',
                labelKey: 'setting.crazyeight.start_hand_size',
                type: GameSettingType::Int,
                default: 7,
                min: 3,
                max: 10,
            ),
        ];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;
        $options = Options::fromState($state);
        $table = $state->table = new Table();

        $deck = $this->buildDeck();
        foreach ($players as $player) {
            $table->add(new Zone('hand:'.$player->id, $player->id, ZoneVisibility::Owner))
                ->push(...array_splice($deck, 0, $options->startHandSize));
        }

        $top = array_pop($deck);
        while ($this->rules->isWild($top) || $this->rules->isAction($top)) {
            array_unshift($deck, $top);
            shuffle($deck);
            $top = array_pop($deck);
        }
        $table->add(new Zone('discard'))->push($top);
        $table->add(new Zone('stock', visibility: ZoneVisibility::Hidden))->push(...$deck);

        $state->data['direction'] = 1;
        $state->data['wishedColor'] = null;
        $state->data['pendingDraw'] = 0;
        $state->data['pendingDrawValue'] = null;
        $state->data['hasDrawn'] = false;
        $state->data['penaltyLocked'] = false;

        $state->logGameEvent('log.crazyeight.started');

        return $state;
    }

    /**
     * @return list<CustomCard>
     */
    private function buildDeck(): array
    {
        $deck = [];
        foreach (self::COLORS as $color) {
            $deck[] = new CustomCard($color, '0');
            for ($n = 1; $n <= 9; ++$n) {
                $deck[] = new CustomCard($color, (string) $n);
                $deck[] = new CustomCard($color, (string) $n);
            }
            foreach ([GameRules::SKIP, GameRules::REVERSE, GameRules::DRAW_TWO] as $action) {
                $deck[] = new CustomCard($color, $action);
                $deck[] = new CustomCard($color, $action);
            }
        }
        for ($i = 0; $i < 4; ++$i) {
            $deck[] = new CustomCard(GameRules::WILD_COLOR, GameRules::WILD);
            $deck[] = new CustomCard(GameRules::WILD_COLOR, GameRules::WILD_FOUR);
        }

        shuffle($deck);

        return $deck;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'play' => $this->play($state, $this->intParam($payload, 'card'), $this->stringParam($payload, 'wish')),
            'draw' => $this->draw($state),
            'pass' => $this->pass($state),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/crazyeight/table.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function play(GameState $state, int $cardIndex, string $wish): void
    {
        $player = $state->currentPlayer();
        $hand = $state->table->hand($player->id);
        $options = Options::fromState($state);

        if (!isset($hand->items[$cardIndex])) {
            $this->invalidMove('error.crazyeight.unknown_card');
        }

        /** @var CustomCard $card */
        $card = $hand->items[$cardIndex];
        $top = $this->top($state);

        if (!$this->rules->playable(
            $card,
            $top,
            $state->data['wishedColor'],
            $state->data['pendingDraw'],
            $state->data['pendingDrawValue'],
            $state->data['penaltyLocked'],
            $options->stackDraw2,
        )) {
            $this->invalidMove('error.crazyeight.not_playable');
        }

        if ($this->rules->isWild($card) && null === $this->colorOrNull($wish)) {
            $this->invalidMove('error.crazyeight.wish_required');
        }

        $hand->removeAt($cardIndex);
        $state->table->zone('discard')->push($card);
        $state->data['wishedColor'] = null;

        $state->logGameEvent('log.crazyeight.played', [
            '%player%' => $player->nickname,
            '%color%' => 't:crazyeight.color.'.$card->color,
            '%value%' => $card->value,
        ]);

        if ($hand->isEmpty()) {
            $state->finish($player->id);
            $state->logGameEvent('log.crazyeight.won', ['%player%' => $player->nickname]);

            return;
        }

        match (true) {
            GameRules::REVERSE === $card->value => $this->applyReverse($state),
            GameRules::SKIP === $card->value => $this->applySkip($state),
            GameRules::DRAW_TWO === $card->value => $this->applyPendingDraw($state, 2, GameRules::DRAW_TWO),
            GameRules::WILD_FOUR === $card->value => $this->applyWild($state, $wish, 4, GameRules::WILD_FOUR),
            GameRules::WILD === $card->value => $this->applyWild($state, $wish, 0, null),
            default => $this->endTurn($state),
        };
    }

    private function applyWild(GameState $state, string $wish, int $penalty, ?string $penaltyValue): void
    {
        $state->data['wishedColor'] = $wish;
        $state->logGameEvent('log.crazyeight.wished', [
            '%player%' => $state->currentPlayer()->nickname,
            '%color%' => 't:crazyeight.color.'.$wish,
        ]);

        if ($penalty > 0) {
            $this->applyPendingDraw($state, $penalty, $penaltyValue);

            return;
        }

        $this->endTurn($state);
    }

    private function applyPendingDraw(GameState $state, int $amount, ?string $value): void
    {
        $state->data['pendingDraw'] += $amount;
        $state->data['pendingDrawValue'] = $value;
        $this->endTurn($state);
    }

    private function applyReverse(GameState $state): void
    {
        $state->data['direction'] *= -1;

        if (2 === \count($state->players)) {
            // heads-up: reversing direction with two players is equivalent to a skip
            return;
        }

        $this->endTurn($state);
    }

    private function applySkip(GameState $state): void
    {
        $this->endTurn($state);
        $this->endTurn($state);
        $state->logGameEvent('log.crazyeight.skipped', ['%player%' => $state->currentPlayer()->nickname]);
    }

    private function draw(GameState $state): void
    {
        $player = $state->currentPlayer();

        if ($state->data['pendingDraw'] > 0) {
            $state->data['penaltyLocked'] = true;
            $this->drawCards($state, $player->id, 1);
            --$state->data['pendingDraw'];
            $state->logGameEvent('log.crazyeight.penalty_drew', [
                '%player%' => $player->nickname,
                '%left%' => $state->data['pendingDraw'],
            ]);

            if ($state->data['pendingDraw'] > 0) {
                return;
            }

            $state->data['pendingDrawValue'] = null;
            $this->endTurn($state);

            return;
        }

        if ($state->data['hasDrawn']) {
            $this->invalidMove('error.crazyeight.already_drawn');
        }

        $this->drawCards($state, $player->id, 1);
        $state->data['hasDrawn'] = true;
        $state->logGameEvent('log.crazyeight.drew', ['%player%' => $player->nickname]);
    }

    private function pass(GameState $state): void
    {
        if (!$state->data['hasDrawn']) {
            $this->invalidMove('error.crazyeight.draw_first');
        }

        $state->logGameEvent('log.crazyeight.passed', ['%player%' => $state->currentPlayer()->nickname]);
        $this->endTurn($state);
    }

    private function endTurn(GameState $state): void
    {
        $state->data['hasDrawn'] = false;
        $state->data['penaltyLocked'] = false;
        $count = \count($state->players);
        $state->currentTurnIndex = (($state->currentTurnIndex + $state->data['direction']) % $count + $count) % $count;
    }

    private function drawCards(GameState $state, string $playerId, int $count): void
    {
        $drawn = Piles::draw(
            $state->table->zone('stock')->items,
            $state->table->zone('discard')->items,
            $count,
            fn () => $state->logGameEvent('log.crazyeight.reshuffled'),
        );
        $state->table->hand($playerId)->push(...$drawn);
    }

    private function top(GameState $state): CustomCard
    {
        return $state->table->zone('discard')->top();
    }

    private function colorOrNull(string $wish): ?string
    {
        return \in_array($wish, self::COLORS, true) ? $wish : null;
    }
}
