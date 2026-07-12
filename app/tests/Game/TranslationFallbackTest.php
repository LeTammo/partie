<?php

declare(strict_types=1);

namespace App\Tests\Game;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TranslationFallbackTest extends KernelTestCase
{
    public function testGameKeysResolveWithoutAnExplicitDomain(): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get(TranslatorInterface::class);

        // key lives in the solitaire domain, requested without a domain
        // (what an {% embed %} block or the generic settings form does)
        self::assertSame('Draw 1', $translator->trans('setting.solitaire.draw_one', [], null, 'en'));
        self::assertSame('1 Karte', $translator->trans('setting.solitaire.draw_one', [], null, 'de'));
        self::assertSame('Draw 1', $translator->trans('setting.solitaire.draw_one', [], 'messages', 'en'));

        // messages-domain keys keep working untouched
        self::assertNotSame('dice.roll', $translator->trans('dice.roll', [], null, 'en'));

        // unknown keys still come back raw
        self::assertSame('no.such.key', $translator->trans('no.such.key', [], null, 'en'));
    }
}
