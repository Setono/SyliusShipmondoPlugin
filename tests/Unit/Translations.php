<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit;

use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * The plugin's own English translations, so a test can assert on the text an admin or a customer reads
 */
final class Translations
{
    public static function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['messages', 'flashes'] as $domain) {
            $translator->addResource('yaml', sprintf('%s/src/Resources/translations/%s.en.yaml', dirname(__DIR__, 2), $domain), 'en', $domain);
        }

        return $translator;
    }
}
