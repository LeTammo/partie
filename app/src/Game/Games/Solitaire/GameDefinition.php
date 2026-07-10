<?php

declare(strict_types=1);

namespace App\Game\Games\Solitaire;

use App\Game\Core\Card\DeckFactory;
use App\Game\Core\Card\PlayingCard;
use App\Game\Core\Card\Suit;
use App\Game\Core\Exception\InvalidMoveException;
use App\Game\Core\Model\GameSetting;
use App\Game\Core\Model\GameSettingType;
use App\Game\Core\Model\GameState;
use App\Game\Core\Service\AbstractGameDefinition;

final readonly class GameDefinition extends AbstractGameDefinition
{
    public function __construct(
        private GameRules    $rules,
        private GameRenderer $renderer,
    ) {
    }

    public function getId(): string
    {
        return 'solitaire';
    }

    public function getName(): string
    {
        return 'game.solitaire.name';
    }

    public function getDescription(): string
    {
        return 'game.solitaire.description';
    }

    public function getIcon(): string
    {
        return 'solitaire';
    }

    public function getMinPlayers(): int
    {
        return 1;
    }

    public function getMaxPlayers(): int
    {
        return 1;
    }

    public function settings(): array
    {
        return [
            new GameSetting(
                key: 'drawCount',
                labelKey: 'setting.solitaire.draw_count',
                type: GameSettingType::Enum,
                default: '1',
                options: ['1' => 'setting.solitaire.draw_one', '3' => 'setting.solitaire.draw_three'],
            ),
        ];
    }

    public function createInitialState(array $players, array $settings = []): GameState
    {
        $state = new GameState($this->getId(), $players);
        $state->data['settings'] = $settings;

        $deck = DeckFactory::deck52();

        $tableau = [];
        for ($col = 0; $col < GameRules::COLUMNS; ++$col) {
            $pile = [];
            for ($i = 0; $i <= $col; ++$i) {
                $pile[] = ['card' => array_pop($deck), 'faceUp' => $i === $col];
            }
            $tableau[] = $pile;
        }
        $state->data['tableau'] = $tableau;

        $foundations = [];
        foreach (Suit::cases() as $suit) {
            $foundations[$suit->value] = [];
        }
        $state->data['foundations'] = $foundations;

        $state->data['stock'] = $deck;
        $state->data['waste'] = [];

        return $state;
    }

    public function applyMove(GameState $state, string $playerId, array $payload): void
    {
        if (!$state->isPlayersTurn($playerId)) {
            throw new InvalidMoveException('error.not_your_turn');
        }

        match ($payload['action'] ?? '') {
            'draw' => $this->draw($state),
            'move' => $this->move($state, $this->stringParam($payload, 'from'), $this->stringParam($payload, 'to')),
            default => throw new InvalidMoveException('error.unknown_action'),
        };
    }

    public function getTemplate(): string
    {
        return 'game/solitaire/board.html.twig';
    }

    public function buildView(GameState $state, ?string $viewerId): array
    {
        return $this->renderer->buildView($state, $viewerId);
    }

    private function draw(GameState $state): void
    {
        $player = $state->currentPlayer();

        if ([] === $state->data['stock']) {
            if ([] === $state->data['waste']) {
                $this->invalidMove('error.solitaire.nothing_to_draw');
            }

            $state->data['stock'] = array_reverse($state->data['waste']);
            $state->data['waste'] = [];
            $state->logGameEvent('log.solitaire.recycled', ['%player%' => $player->nickname]);

            return;
        }

        $count = (int) ($this->setting($state, 'drawCount') ?? 1);
        for ($i = 0; $i < $count && [] !== $state->data['stock']; ++$i) {
            $state->data['waste'][] = array_pop($state->data['stock']);
        }
        $state->logGameEvent('log.solitaire.drew', ['%player%' => $player->nickname]);
    }

    private function move(GameState $state, string $from, string $to): void
    {
        $fromCol = $this->columnOf($from);
        if (null !== $fromCol && $to === 'tableau:'.$fromCol) {
            $this->invalidMove('error.solitaire.invalid_move');
        }

        $cards = $this->cardsAt($state, $from);
        if (null === $cards) {
            $this->invalidMove('error.solitaire.invalid_move');
        }

        if (\count($cards) > 1 && str_starts_with($to, 'foundation:')) {
            $this->invalidMove('error.solitaire.invalid_move');
        }

        if (!$this->canPlace($state, $to, $cards[0])) {
            $this->invalidMove('error.solitaire.invalid_move');
        }

        $this->removeFrom($state, $from, \count($cards));
        $this->appendTo($state, $to, $cards);
        $this->flipExposedCard($state, $from);

        $player = $state->currentPlayer();
        $state->logGameEvent('log.solitaire.moved', ['%player%' => $player->nickname]);

        if ($this->isWon($state)) {
            $state->finish($player->id);
            $state->logEvent('log.won', ['%player%' => $player->nickname]);
        }
    }

    private function columnOf(string $location): ?int
    {
        return preg_match('/^tableau:(\d+)/', $location, $m) ? (int) $m[1] : null;
    }

    /**
     * @return list<PlayingCard>|null
     */
    private function cardsAt(GameState $state, string $from): ?array
    {
        if ('waste' === $from) {
            $waste = $state->data['waste'];

            return [] !== $waste ? [$waste[array_key_last($waste)]] : null;
        }

        if (!preg_match('/^tableau:(\d+):(\d+)$/', $from, $m)) {
            return null;
        }

        $pile = $state->data['tableau'][(int) $m[1]] ?? null;
        $index = (int) $m[2];
        if (null === $pile || !isset($pile[$index]) || !$pile[$index]['faceUp']) {
            return null;
        }

        return array_map(static fn (array $slot): PlayingCard => $slot['card'], \array_slice($pile, $index));
    }

    private function canPlace(GameState $state, string $to, PlayingCard $bottomOfRun): bool
    {
        if (preg_match('/^tableau:(\d+)$/', $to, $m)) {
            $pile = $state->data['tableau'][(int) $m[1]] ?? null;

            return null !== $pile && $this->rules->canDropOnTableau($bottomOfRun, $pile);
        }

        if (preg_match('/^foundation:(\w+)$/', $to, $m)) {
            $suit = Suit::tryFrom($m[1]);
            if (null === $suit || $suit !== $bottomOfRun->suit) {
                return false;
            }

            return $this->rules->canDropOnFoundation($bottomOfRun, $state->data['foundations'][$suit->value]);
        }

        return false;
    }

    private function removeFrom(GameState $state, string $from, int $count): void
    {
        if ('waste' === $from) {
            array_pop($state->data['waste']);

            return;
        }

        $col = $this->columnOf($from);
        array_splice($state->data['tableau'][$col], -$count);
    }

    /**
     * @param list<PlayingCard> $cards
     */
    private function appendTo(GameState $state, string $to, array $cards): void
    {
        if (preg_match('/^foundation:(\w+)$/', $to, $m)) {
            array_push($state->data['foundations'][$m[1]], ...$cards);

            return;
        }

        $col = $this->columnOf($to);
        foreach ($cards as $card) {
            $state->data['tableau'][$col][] = ['card' => $card, 'faceUp' => true];
        }
    }

    private function flipExposedCard(GameState $state, string $from): void
    {
        $col = $this->columnOf($from);
        if (null === $col) {
            return;
        }

        $pile = &$state->data['tableau'][$col];
        if ([] !== $pile) {
            $pile[array_key_last($pile)]['faceUp'] = true;
        }
    }

    private function isWon(GameState $state): bool
    {
        foreach ($state->data['foundations'] as $pile) {
            if (13 !== \count($pile)) {
                return false;
            }
        }

        return true;
    }
}
