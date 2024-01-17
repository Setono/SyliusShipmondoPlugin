<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DependencyInjection;

use Setono\SyliusShipmondoPlugin\Model\RemoteEvent;
use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Bundle\ResourceBundle\Form\Type\DefaultResourceType;
use Sylius\Component\Resource\Factory\Factory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
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
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('webhooks')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('key')
                            ->info('This is the key that Shipmondo will use to encrypt the webhook payload')
                            ->defaultValue('%env(SHIPMONDO_WEBHOOKS_KEY)%')
                            ->cannotBeEmpty()
        ;

        $this->addResourcesSection($rootNode);

        return $treeBuilder;
    }

    private function addResourcesSection(ArrayNodeDefinition $node): void
    {
        /**
         * @psalm-suppress MixedMethodCall,UndefinedInterfaceMethod,PossiblyUndefinedMethod,PossiblyNullReference
         */
        $node
            ->children()
                ->arrayNode('resources')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('remote_event')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->variableNode('options')->end()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(RemoteEvent::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('repository')->cannotBeEmpty()->end()
                                        ->scalarNode('form')->defaultValue(DefaultResourceType::class)->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->end()
        ;
    }
}
