<?php

declare(strict_types=1);

namespace App\Game\Games\MauMau;

use App\Game\Core\Card\DeckFactory;
use App\Game\Core\Card\Piles;
use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Rank;
use App\Game\Core\Card\Suit;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameSetting;
use App\Game\Core\Model\GameSettingType;
use App\Game\Core\Model\GameState;
use App\Game\Core\Service\AbstractGameDefinition;

final readonly class GameDefinition extends AbstractGameDefinition
{
    private const int HAND_SIZE = 5;

    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'maumau';
    }

    public function getName(): string
    {
        return 'game.maumau.name';
    }

    public function getDescription(): string
    {
        return 'game.maumau.description';
    }

    public function getIcon(): string
    {
        return 'card-stack';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 5;
    }

    public function settings(): array
    {
        $rankOptions = $this->rankOptions();

        return [
            new GameSetting(
                key: 'skipRank',
                labelKey: 'setting.maumau.skip_rank',
                type: GameSettingType::Enum,
                default: (string) Rank::Eight->value,
                options: $rankOptions,
            ),
            new GameSetting(
                key: 'drawRank',
                labelKey: 'setting.maumau.draw_rank',
                type: GameSettingType::Enum,
                default: (string) Rank::Seven->value,
                options: $rankOptions,
            ),
            new GameSetting(
                key: 'stackDraw',
                labelKey: 'setting.maumau.stack_draw',
                type: GameSettingType::Bool,
                default: true,
            ),
            new GameSetting(
                key: 'stackSkip',
                labelKey: 'setting.maumau.stack_skip',
                type: GameSettingType::Bool,
                default: false,
            ),
            new GameSetting(
                key: 'allowRewish',
                labelKey: 'setting.maumau.allow_rewish',
                type: GameSettingType::Bool,
                default: false,
            ),
        ];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;

        $deck = DeckFactory::deck32();
        foreach ($players as $player) {
            $state->data['hands'][$player->id] = array_splice($deck, 0, self::HAND_SIZE);
        }
        $state->data['discard'] = array_splice($deck, 0, 1);
        $state->data['drawPile'] = $deck;
        $state->data['wishedSuit'] = null;
        $state->data['pendingDraw'] = 0;
        $state->data['pendingSkip'] = 0;
        $state->data['hasDrawn'] = false;
        $state->data['penaltyLocked'] = false;

        $state->logGameEvent('log.maumau.started');

        return $state;
    }

    /**
     * @return array<string, string>
     */
    private function rankOptions(): array
    {
        $options = [];
        foreach (Rank::cases() as $rank) {
            if (Rank::Jack === $rank) {
                continue;
            }
            $options[(string) $rank->value] = 'card.rank.'.$rank->labelKey();
        }

        return $options;
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
        return 'game/maumau/table.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function play(GameState $state, int $cardIndex, string $wish): void
    {
        $player = $state->currentPlayer();
        $hand = &$state->data['hands'][$player->id];
        $options = Options::fromState($state);

        if (!isset($hand[$cardIndex])) {
            $this->invalidMove('error.maumau.unknown_card');
        }

        $card = $hand[$cardIndex];
        $top = $this->top($state);
        if (!$this->rules->playable($card, $top, $state->data['wishedSuit'], $state->data['pendingDraw'], $state->data['penaltyLocked'], $state->data['pendingSkip'], $options)) {
            $this->invalidMove('error.maumau.not_playable');
        }

        if (Rank::Jack === $card->rank && null === Suit::tryFrom($wish)) {
            $this->invalidMove('error.maumau.wish_required');
        }

        array_splice($hand, $cardIndex, 1);
        $state->data['discard'][] = $card;
        $state->data['wishedSuit'] = null;

        $state->logGameEvent('log.maumau.played', [
            '%player%' => $player->nickname,
            '%suit%' => $card->suit->symbol(),
            '%rank%' => 't:card.rank.'.$card->rank->labelKey(),
        ]);

        if ([] === $hand) {
            $state->finish($player->id);
            $state->logGameEvent('log.maumau.won', ['%player%' => $player->nickname]);

            return;
        }

        if ($card->rank === $options->drawRank) {
            $state->data['pendingDraw'] += GameRules::DRAW_PENALTY;
        } elseif ($card->rank === $options->skipRank) {
            ++$state->data['pendingSkip'];
        } elseif (Rank::Jack === $card->rank) {
            $state->data['wishedSuit'] = $wish;
            $state->logGameEvent('log.maumau.wished', [
                '%player%' => $player->nickname,
                '%suit%' => Suit::from($wish)->symbol(),
            ]);
        }

        $this->endTurn($state);

        if ($card->rank === $options->skipRank && !$options->stackSkip) {
            $state->logGameEvent('log.maumau.skipped', ['%player%' => $state->currentPlayer()->nickname]);
            $state->data['pendingSkip'] = 0;
            $state->advanceTurn();
        }
    }

    private function draw(GameState $state): void
    {
        $player = $state->currentPlayer();

        if ($state->data['pendingDraw'] > 0) {
            $state->data['penaltyLocked'] = true;
            $this->drawCards($state, $player->id, 1);
            --$state->data['pendingDraw'];
            $state->logGameEvent('log.maumau.penalty_drew', [
                '%player%' => $player->nickname,
                '%left%' => $state->data['pendingDraw'],
            ]);

            if ($state->data['pendingDraw'] > 0) {
                return;
            }

            $this->endTurn($state);

            return;
        }

        if ($state->data['hasDrawn']) {
            $this->invalidMove('error.maumau.already_drawn');
        }

        $this->drawCards($state, $player->id, 1);
        $state->data['hasDrawn'] = true;
        $state->logGameEvent('log.maumau.drew', ['%player%' => $player->nickname]);
    }

    private function pass(GameState $state): void
    {
        if ($state->data['pendingSkip'] > 0) {
            $player = $state->currentPlayer();
            --$state->data['pendingSkip'];
            $state->logGameEvent('log.maumau.skipped', ['%player%' => $player->nickname]);
            $state->advanceTurn();

            return;
        }

        if (!$state->data['hasDrawn']) {
            $this->invalidMove('error.maumau.draw_first');
        }

        $state->logGameEvent('log.maumau.passed', ['%player%' => $state->currentPlayer()->nickname]);
        $this->endTurn($state);
    }

    private function endTurn(GameState $state): void
    {
        $state->data['hasDrawn'] = false;
        $state->data['penaltyLocked'] = false;
        $state->advanceTurn();
    }

    private function drawCards(GameState $state, string $playerId, int $count): void
    {
        $drawn = Piles::draw(
            $state->data['drawPile'],
            $state->data['discard'],
            $count,
            fn () => $state->logGameEvent('log.maumau.reshuffled'),
        );
        array_push($state->data['hands'][$playerId], ...$drawn);
    }

    private function top(GameState $state): PlayingCard
    {
        return $state->data['discard'][array_key_last($state->data['discard'])];
    }
}
