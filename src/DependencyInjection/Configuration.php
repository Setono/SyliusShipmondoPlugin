<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('setono_sylius_shipmondo');
        $rootNode = $treeBuilder->getRootNode();

        /** @psalm-suppress UndefinedInterfaceMethod,PossiblyNullReference,MixedMethodCall */
        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('api')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('username')
                            ->defaultValue('%env(SHIPMONDO_USERNAME)%')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('key')
                            ->defaultValue('%env(SHIPMONDO_KEY)%')
                            ->cannotBeEmpty()
        ;

        return $treeBuilder;
    }
}
