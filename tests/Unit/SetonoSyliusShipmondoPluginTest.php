<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use PHPUnit\Framework\TestCase;
use Setono\CompositeCompilerPass\CompositeCompilerPass;
use Setono\SyliusShipmondoPlugin\SetonoSyliusShipmondoPlugin;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class SetonoSyliusShipmondoPluginTest extends TestCase
{
    /**
     * @test
     */
    public function it_supports_the_doctrine_orm_driver(): void
    {
        self::assertSame([SyliusResourceBundle::DRIVER_DOCTRINE_ORM], (new SetonoSyliusShipmondoPlugin())->getSupportedDrivers());
    }

    /**
     * @test
     */
    public function it_registers_the_doctrine_mapping_and_the_data_mapper_composite_compiler_pass(): void
    {
        $container = new ContainerBuilder();
        (new SetonoSyliusShipmondoPlugin())->build($container);

        $passes = $container->getCompilerPassConfig()->getBeforeOptimizationPasses();

        // registered by the parent resource bundle
        self::assertNotEmpty(array_filter($passes, static fn (object $pass): bool => $pass instanceof DoctrineOrmMappingsPass));

        $compositePasses = array_filter($passes, static fn (object $pass): bool => $pass instanceof CompositeCompilerPass);
        self::assertCount(1, $compositePasses);

        // run the composite pass against a container with the composite and a tagged data mapper
        $target = new ContainerBuilder();
        $target->register('setono_sylius_shipmondo.data_mapper.sales_order.composite');
        $target->register('mapper')->addTag('setono_sylius_shipmondo.sales_order_data_mapper');

        foreach ($compositePasses as $pass) {
            $pass->process($target);
        }

        self::assertEquals(
            [['add', [new Reference('mapper')]]],
            $target->getDefinition('setono_sylius_shipmondo.data_mapper.sales_order.composite')->getMethodCalls(),
        );
    }
}
