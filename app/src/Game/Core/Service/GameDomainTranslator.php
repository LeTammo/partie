<?php

declare(strict_types=1);

namespace App\Game\Core\Service;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Twig's {% trans_default_domain %} is per-template and does not reach into
// {% embed %} blocks, so game strings rendered inside shared component
// embeds would fall back to the 'messages' domain and show up raw. This
// decorator retries a key that is missing in 'messages' against the domain
// named inside the key itself ('setting.solitaire.draw_one' -> 'solitaire',
// 'log.elevenout.won' -> 'elevenout'), so templates never need to pass a
// domain explicitly.
#[AsDecorator('translator')]
final class GameDomainTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner,
    ) {
    }

    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        if ((null === $domain || 'messages' === $domain) && str_contains($id, '.')) {
            $catalogue = $this->inner->getCatalogue($locale);
            if (!$catalogue->has($id, 'messages')) {
                foreach (explode('.', $id) as $segment) {
                    if ('' !== $segment && $catalogue->has($id, $segment)) {
                        return $this->inner->trans($id, $parameters, $segment, $locale);
                    }
                }
            }
        }

        return $this->inner->trans($id, $parameters, $domain, $locale);
    }

    public function getCatalogue(?string $locale = null): \Symfony\Component\Translation\MessageCatalogueInterface
    {
        return $this->inner->getCatalogue($locale);
    }

    public function getCatalogues(): array
    {
        return $this->inner->getCatalogues();
    }

    public function getLocale(): string
    {
        return $this->inner->getLocale();
    }

    public function setLocale(string $locale): void
    {
        $this->inner->setLocale($locale);
    }
}
