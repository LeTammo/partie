<?php

declare(strict_types=1);

namespace App\Game\Games\MauMau;

use App\Game\Core\Card\DeckFactory;
use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Rank;
use App\Game\Core\Card\Suit;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Service\AbstractGameDefinition;

final readonly class GameDefinition extends AbstractGameDefinition
{
    private const int HAND_SIZE = 5;

    public function __construct(
        private readonly GameRules $rules,
        private readonly GameRenderer $renderer,
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

    public function createInitialState(array $players): GameState
    {
        $state = new GameState($this->getId(), $players);

        $deck = DeckFactory::deck32();
        foreach ($players as $player) {
            $state->data['hands'][$player->id] = array_splice($deck, 0, self::HAND_SIZE);
        }
        $state->data['discard'] = array_splice($deck, 0, 1);
        $state->data['drawPile'] = $deck;
        $state->data['wishedSuit'] = null;
        $state->data['pendingDraw'] = 0;
        $state->data['hasDrawn'] = false;
        $state->data['penaltyLocked'] = false;

        $state->logGameEvent('log.maumau.started');

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'play' => $this->play($state, (int) ($payload['card'] ?? -1), (string) ($payload['wish'] ?? '')),
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

        if (!isset($hand[$cardIndex])) {
            $this->invalidMove('error.maumau.unknown_card');
        }

        $card = $hand[$cardIndex];
        $top = $this->top($state);
        if (!$this->rules->playable($card, $top, $state->data['wishedSuit'], $state->data['pendingDraw'], $state->data['penaltyLocked'])) {
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

        if (Rank::Seven === $card->rank) {
            $state->data['pendingDraw'] += GameRules::DRAW_PENALTY;
        } elseif (Rank::Jack === $card->rank) {
            $state->data['wishedSuit'] = $wish;
            $state->logGameEvent('log.maumau.wished', [
                '%player%' => $player->nickname,
                '%suit%' => Suit::from($wish)->symbol(),
            ]);
        }

        $this->endTurn($state);

        if (Rank::Eight === $card->rank) {
            $state->logGameEvent('log.maumau.skipped', ['%player%' => $state->currentPlayer()->nickname]);
            $state->advanceTurn();
        }
    }

    /**
     * Always draws exactly one card, so the player drags one card at a time
     * from the pile into their hand. A stacked penalty (from playing 7s)
     * must be drawn one card per request until it is fully paid off.
     */
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
        if (!$state->data['hasDrawn']) {
            $this->invalidMove('error.maumau.draw_first');
        }

        $state->logGameEvent('log.maumau.passed', ['%player%' => $state->currentPlayer()->nickname]);
        $this->endTurn($state);
    }

    /** Clears the per-turn transient flags and advances to the next player. */
    private function endTurn(GameState $state): void
    {
        $state->data['hasDrawn'] = false;
        $state->data['penaltyLocked'] = false;
        $state->advanceTurn();
    }

    private function drawCards(GameState $state, string $playerId, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            if ([] === $state->data['drawPile']) {
                $this->reshuffle($state);
                if ([] === $state->data['drawPile']) {
                    return;
                }
            }
            $state->data['hands'][$playerId][] = array_pop($state->data['drawPile']);
        }
    }

    private function reshuffle(GameState $state): void
    {
        $discard = $state->data['discard'];
        if (\count($discard) <= 1) {
            return;
        }

        $top = array_pop($discard);
        shuffle($discard);
        $state->data['drawPile'] = $discard;
        $state->data['discard'] = [$top];
        $state->logGameEvent('log.maumau.reshuffled');
    }

    private function top(GameState $state): PlayingCard
    {
        return $state->data['discard'][array_key_last($state->data['discard'])];
    }
}
