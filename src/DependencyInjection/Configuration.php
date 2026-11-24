<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DependencyInjection;

use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooks;
use Setono\SyliusShipmondoPlugin\Repository\RegisteredWebhooksRepository;
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

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('order_id_prefix')
                    ->info('Put before the order number in the order id sent to Shipmondo, e.g. with WEB- the order 000001234 becomes the sales order WEB-000001234. Give each shop that shares a Shipmondo account its own. Don\'t set or change it while any order is unlinked or uploading, or those orders are uploaded a second time')
                    ->defaultValue('')
                    ->treatNullLike('')
                ->end()
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
                        ->scalarNode('sandbox')
                            ->info('If true, the Shipmondo sandbox API is used instead of the production API. Defaults to the SHIPMONDO_SANDBOX environment variable (false if unset).')
                            ->defaultValue('%env(bool:SHIPMONDO_SANDBOX)%')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('webhooks')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('key')
                            ->info('This is the key that Shipmondo will use to sign the webhooks. The webhooks are named after an id derived from it, so give each shop on a Shipmondo account its own key')
                            ->defaultValue('%env(SHIPMONDO_WEBHOOKS_KEY)%')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('name_prefix')
                            ->info('This is the prefix that will be added to the name of the webhooks, followed by the shop\'s id')
                            ->defaultValue('Setono Sylius Shipmondo Plugin')
                            ->cannotBeEmpty()
        ;

        $this->addResourcesSection($rootNode);

        return $treeBuilder;
    }

    private function addResourcesSection(ArrayNodeDefinition $node): void
    {
        $node
            ->children()
                ->arrayNode('resources')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('registered_webhooks')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->variableNode('options')->end()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(RegisteredWebhooks::class)->cannotBeEmpty()->end()
                                        ->scalarNode('repository')->defaultValue(RegisteredWebhooksRepository::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
        ;
    }
}
