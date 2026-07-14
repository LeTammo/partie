<?php

declare(strict_types=1);

namespace App\Tests\Game;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Renders the shared UI components with realistic parameters - catches
 * runtime Twig errors (undefined variables, bad filters) that lint:twig
 * cannot see.
 */
final class ComponentRenderTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    public function testTokenVariants(): void
    {
        // three areas + ring + shadow
        $html = $this->twig->render('components/token.html.twig', [
            'outer' => '#111111',
            'middle' => '#222222',
            'center' => '#333333',
            'ring' => true,
            'size' => 'size-8',
            'flip' => 'piece-x',
            'exit' => 'fade',
        ]);
        self::assertStringContainsString('#111111', $html);
        self::assertStringContainsString('#222222', $html);
        self::assertStringContainsString('#333333', $html);
        self::assertStringContainsString('data-flip-id="piece-x"', $html);
        self::assertStringContainsString('ring-2', $html);

        // center dot only (RowFour/Checkers look)
        $html = $this->twig->render('components/token.html.twig', [
            'outer' => '#aaa', 'center' => '#bbb', 'centerSize' => 45,
        ]);
        self::assertStringContainsString('width: 45%', $html);

        // icon center (Checkers king)
        $html = $this->twig->render('components/token.html.twig', [
            'outer' => '#aaa', 'center' => '#bbb', 'icon' => 'crown', 'symbolColor' => '#bbb',
        ]);
        self::assertStringContainsString('<svg', $html);

        // plain symbol token (TicTacToe)
        $html = $this->twig->render('components/token.html.twig', [
            'shape' => 'plain', 'symbol' => '✕', 'symbolColor' => '#c00', 'size' => '',
        ]);
        self::assertStringContainsString('✕', $html);
        self::assertStringContainsString('#c00', $html);

        // attributes for interaction wiring
        $html = $this->twig->render('components/token.html.twig', [
            'outer' => '#aaa',
            'attr' => ['draggable' => 'true', 'data-x' => 3],
        ]);
        self::assertStringContainsString('draggable="true"', $html);
        self::assertStringContainsString('data-x="3"', $html);
    }

    public function testChipAndStack(): void
    {
        $html = $this->twig->render('components/chip.html.twig', [
            'color' => 'var(--color-sage-500)', 'label' => '20',
        ]);
        self::assertStringContainsString('var(--color-sage-500)', $html);
        self::assertStringContainsString('20', $html);

        $html = $this->twig->render('components/chip_stack.html.twig', [
            'chips' => \App\Game\Core\View\ChipViews::stack(85),
            'total' => 85,
        ]);
        self::assertStringContainsString('85', $html);
    }

    public function testDieFaces(): void
    {
        $pips = $this->twig->render('components/die.html.twig', ['value' => 5]);
        self::assertSame(5, substr_count($pips, '<circle'));

        $symbol = $this->twig->render('components/die.html.twig', ['value' => 2, 'symbol' => 'A']);
        self::assertStringContainsString('>A</text>', $symbol);
        self::assertStringNotContainsString('<circle', $symbol);
    }

    public function testPileWithoutForm(): void
    {
        $withTop = $this->twig->render('components/pile.html.twig', [
            'top' => ['rank' => 'ace', 'suit' => '♥', 'red' => true, 'joker' => false],
            'label' => 'Discard',
        ]);
        self::assertStringContainsString('Discard', $withTop);

        $empty = $this->twig->render('components/pile.html.twig', ['emptySymbol' => '♥']);
        self::assertStringContainsString('border-dashed', $empty);

        $dragSource = $this->twig->render('components/pile.html.twig', [
            'top' => ['rank' => 'ace', 'suit' => '♥', 'red' => true, 'joker' => false],
            'buttonAttr' => ['data-source' => 'waste', 'draggable' => 'true'],
        ]);
        self::assertStringContainsString('<button type="button"', $dragSource);
        self::assertStringContainsString('data-source="waste"', $dragSource);

        $dropZone = $this->twig->render('components/pile.html.twig', [
            'emptySymbol' => '♠',
            'emptyClass' => 'border-warmgray-200 text-terracotta-300',
            'attr' => ['data-zone' => 'foundation:spades', 'data-mode' => 'replace'],
        ]);
        self::assertStringContainsString('data-zone="foundation:spades"', $dropZone);
        self::assertStringContainsString('text-terracotta-300', $dropZone);
    }

    public function testTableAreaAndPlayerBanner(): void
    {
        $area = $this->twig->render('components/table_area.html.twig', [
            'title' => 'Middle', 'active' => true,
        ]);
        self::assertStringContainsString('Middle', $area);
        self::assertStringContainsString('ring-2', $area);

        self::assertSame('', trim($this->twig->render('components/table_area.html.twig', ['hidden' => true])));

        foreach (['inline', 'tile', 'row'] as $variant) {
            $banner = $this->twig->render('components/player_banner.html.twig', [
                'p' => ['nickname' => 'Alice', 'color' => '#abc', 'current' => true],
                'variant' => $variant,
            ]);
            self::assertStringContainsString('Alice', $banner, $variant);
            self::assertStringContainsString('#abc', $banner, $variant);
        }
    }

    public function testCardSizesAndBack(): void
    {
        $md = $this->twig->render('components/card.html.twig', [
            'rank' => 'ace', 'suit' => '♥', 'red' => true, 'joker' => false,
        ]);
        self::assertStringContainsString('h-24 w-16', $md);

        $back = $this->twig->render('components/card.html.twig', [
            'back' => true, 'backColor' => '#123456',
        ]);
        self::assertStringContainsString('#123456', $back);
    }

    public function testCustomFaceCard(): void
    {
        $html = $this->twig->render('components/card.html.twig', [
            'value' => '11', 'color' => 'green',
        ]);
        self::assertStringContainsString('11', $html);
        self::assertStringContainsString('bg-sage-50', $html);

        // unknown color names fall back to a neutral face
        $neutral = $this->twig->render('components/card.html.twig', [
            'value' => 'A', 'color' => 'no-such-color',
        ]);
        self::assertStringContainsString('bg-white', $neutral);
    }
}
