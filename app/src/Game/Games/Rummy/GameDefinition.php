<?php

declare(strict_types=1);

namespace App\Game\Games\Rummy;

use App\Game\Core\Card\DeckFactory;
use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameState;
use App\Game\Core\Service\AbstractGameDefinition;

final readonly class GameDefinition extends AbstractGameDefinition
{
    private const int HAND_SIZE = 13;

    public function __construct(
        private readonly GameRules $rules,
        private readonly GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'rummy';
    }

    public function getName(): string
    {
        return 'game.rummy.name';
    }

    public function getDescription(): string
    {
        return 'game.rummy.description';
    }

    public function getIcon(): string
    {
        return 'card-fan';
    }

    public function getMinPlayers(): int
    {
        return 2;
    }

    public function getMaxPlayers(): int
    {
        return 6;
    }

    public function createInitialState(array $players): GameState
    {
        $state = new GameState($this->getId(), $players);

        $deck = DeckFactory::deck110();
        foreach ($players as $player) {
            $state->data['hands'][$player->id] = array_splice($deck, 0, self::HAND_SIZE);
            $state->data['hasMelded'][$player->id] = false;
        }
        $state->data['discard'] = array_splice($deck, 0, 1);
        $state->data['stock'] = $deck;
        $state->data['melds'] = [];
        $state->data['hasDrawn'] = false;
        $state->data['turnMelds'] = [];
        $state->data['turnMeldPoints'] = 0;

        $state->logGameEvent('log.rummy.started');

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'draw' => $this->draw($state),
            'takediscard' => $this->takeDiscard($state),
            'meld' => $this->meld($state, $this->indexes($payload)),
            'layoff' => $this->layoff($state, $this->indexes($payload), (int) ($payload['meld'] ?? -1)),
            'discard' => $this->discard($state, $this->indexes($payload)),
            'takeback' => $this->takeback($state),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/rummy/table.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function draw(GameState $state): void
    {
        $this->assertNotDrawn($state);

        if ([] === $state->data['stock']) {
            $this->reshuffle($state);
            if ([] === $state->data['stock']) {
                $this->invalidMove('error.rummy.stock_empty');
            }
        }

        $player = $state->currentPlayer();
        $state->data['hands'][$player->id][] = array_pop($state->data['stock']);
        $state->data['hasDrawn'] = true;
        $state->logGameEvent('log.rummy.drew', ['%player%' => $player->nickname]);
    }

    private function takeDiscard(GameState $state): void
    {
        $this->assertNotDrawn($state);

        if ([] === $state->data['discard']) {
            $this->invalidMove('error.rummy.discard_empty');
        }

        $player = $state->currentPlayer();
        $card = array_pop($state->data['discard']);
        $state->data['hands'][$player->id][] = $card;
        $state->data['hasDrawn'] = true;
        $state->logGameEvent('log.rummy.took_discard', ['%player%' => $player->nickname]);
    }

    /**
     * @param list<int> $indexes
     */
    private function meld(GameState $state, array $indexes): void
    {
        $this->assertDrawn($state);
        $player = $state->currentPlayer();
        $hand = &$state->data['hands'][$player->id];

        if (\count($indexes) < 3) {
            $this->invalidMove('error.rummy.select_meld');
        }
        $cards = $this->pick($hand, $indexes);
        if (\count($hand) - \count($indexes) < 1) {
            $this->invalidMove('error.rummy.keep_one');
        }

        $meld = $this->rules->validateMeld($cards);
        if (null === $meld) {
            $this->invalidMove('error.rummy.invalid_meld');
        }

        $this->remove($hand, $indexes);
        $state->data['melds'][] = [
            'ownerId' => $player->id,
            'type' => $meld['type'],
            'cards' => $cards,
        ];

        $state->logGameEvent('log.rummy.melded', [
            '%player%' => $player->nickname,
            '%points%' => $meld['points'],
        ]);

        if (!$state->data['hasMelded'][$player->id]) {
            $state->data['turnMelds'][] = \count($state->data['melds']) - 1;
            $state->data['turnMeldPoints'] += $meld['points'];
            if ($state->data['turnMeldPoints'] >= GameRules::INITIAL_MELD_POINTS) {
                $state->data['hasMelded'][$player->id] = true;
                $state->data['turnMelds'] = [];
                $state->logGameEvent('log.rummy.opened', ['%player%' => $player->nickname]);
            }
        }
    }

    /**
     * @param list<int> $indexes
     */
    private function layoff(GameState $state, array $indexes, int $meldIndex): void
    {
        $this->assertDrawn($state);
        $player = $state->currentPlayer();
        $hand = &$state->data['hands'][$player->id];

        if (!$state->data['hasMelded'][$player->id]) {
            $this->invalidMove('error.rummy.open_first');
        }
        if (1 !== \count($indexes)) {
            $this->invalidMove('error.rummy.select_one');
        }
        if (!isset($state->data['melds'][$meldIndex])) {
            $this->invalidMove('error.rummy.unknown_meld');
        }
        if (\count($hand) - 1 < 1) {
            $this->invalidMove('error.rummy.keep_one');
        }

        $card = $this->pick($hand, $indexes)[0];
        $meld = $state->data['melds'][$meldIndex];
        $result = $this->rules->validateMeld([...$meld['cards'], $card]);
        if (null === $result || $result['type'] !== $meld['type']) {
            $this->invalidMove('error.rummy.does_not_fit');
        }

        $this->remove($hand, $indexes);
        $state->data['melds'][$meldIndex]['cards'][] = $card;
        $state->logGameEvent('log.rummy.laid_off', ['%player%' => $player->nickname]);
    }

    /**
     * @param list<int> $indexes
     */
    private function discard(GameState $state, array $indexes): void
    {
        $this->assertDrawn($state);
        $player = $state->currentPlayer();
        $hand = &$state->data['hands'][$player->id];

        if (1 !== \count($indexes)) {
            $this->invalidMove('error.rummy.select_one');
        }
        if (!$state->data['hasMelded'][$player->id] && $state->data['turnMeldPoints'] > 0) {
            $this->invalidMove('error.rummy.initial_meld');
        }

        $card = $this->pick($hand, $indexes)[0];
        $this->remove($hand, $indexes);
        $state->data['discard'][] = $card;

        $state->logGameEvent('log.rummy.discarded', [
            '%player%' => $player->nickname,
            '%suit%' => $card->joker ? '' : $card->suit->symbol(),
            '%rank%' => $card->joker ? 't:card.joker' : 't:card.rank.'.$card->rank->labelKey(),
        ]);

        if ([] === $hand) {
            $state->finish($player->id);
            $state->logGameEvent('log.rummy.won', ['%player%' => $player->nickname]);

            return;
        }

        $state->data['hasDrawn'] = false;
        $state->data['turnMelds'] = [];
        $state->data['turnMeldPoints'] = 0;
        $state->advanceTurn();
    }

    private function takeback(GameState $state): void
    {
        if ([] === $state->data['turnMelds']) {
            $this->invalidMove('error.rummy.nothing_to_takeback');
        }

        $player = $state->currentPlayer();
        $hand = &$state->data['hands'][$player->id];

        rsort($state->data['turnMelds']);
        foreach ($state->data['turnMelds'] as $meldIndex) {
            $meld = $state->data['melds'][$meldIndex];
            foreach ($meld['cards'] as $card) {
                $hand[] = $card;
            }
            array_splice($state->data['melds'], $meldIndex, 1);
        }
        $state->data['turnMelds'] = [];
        $state->data['turnMeldPoints'] = 0;

        $state->logGameEvent('log.rummy.takeback', ['%player%' => $player->nickname]);
    }

    private function reshuffle(GameState $state): void
    {
        $discard = $state->data['discard'];
        if (\count($discard) <= 1) {
            return;
        }

        $top = array_pop($discard);
        shuffle($discard);
        $state->data['stock'] = $discard;
        $state->data['discard'] = [$top];
        $state->logGameEvent('log.rummy.reshuffled');
    }

    private function assertNotDrawn(GameState $state): void
    {
        if ($state->data['hasDrawn']) {
            $this->invalidMove('error.rummy.already_drawn');
        }
    }

    private function assertDrawn(GameState $state): void
    {
        if (!$state->data['hasDrawn']) {
            $this->invalidMove('error.rummy.draw_first');
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<int>
     */
    private function indexes(array $payload): array
    {
        $raw = trim((string) ($payload['cards'] ?? ''));
        if ('' === $raw) {
            return [];
        }

        $indexes = [];
        foreach (explode(',', $raw) as $part) {
            if (is_numeric($part)) {
                $indexes[] = (int) $part;
            }
        }

        return array_values(array_unique($indexes));
    }

    /**
     * @param list<PlayingCard> $hand
     * @param list<int> $indexes
     *
     * @return list<PlayingCard>
     */
    private function pick(array $hand, array $indexes): array
    {
        $cards = [];
        foreach ($indexes as $index) {
            if (!isset($hand[$index])) {
                $this->invalidMove('error.rummy.unknown_card');
            }
            $cards[] = $hand[$index];
        }

        return $cards;
    }

    /**
     * @param list<PlayingCard> $hand
     * @param list<int> $indexes
     */
    private function remove(array &$hand, array $indexes): void
    {
        rsort($indexes);
        foreach ($indexes as $index) {
            array_splice($hand, $index, 1);
        }
    }
}
