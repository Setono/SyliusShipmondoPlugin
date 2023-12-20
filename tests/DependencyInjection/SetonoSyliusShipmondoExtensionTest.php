<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\DependencyInjection;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use Setono\SyliusShipmondoPlugin\DependencyInjection\SetonoSyliusShipmondoExtension;

/**
 * See examples of tests and configuration options here: https://github.com/SymfonyTest/SymfonyDependencyInjectionTest
 */
final class SetonoSyliusShipmondoExtensionTest extends AbstractExtensionTestCase
{
    protected function getContainerExtensions(): array
    {
        return [
            new SetonoSyliusShipmondoExtension(),
        ];
    }

    /**
     * @test
     */
    public function after_loading_the_correct_parameter_has_been_set(): void
    {
        $this->load();

        $this->assertContainerBuilderHasParameter('setono_sylius_shipmondo.api.username', '%env(SHIPMONDO_USERNAME)%');
        $this->assertContainerBuilderHasParameter('setono_sylius_shipmondo.api.key', '%env(SHIPMONDO_KEY)%');
    }
}
