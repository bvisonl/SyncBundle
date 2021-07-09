<?php

namespace NTI\SyncBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/configuration.html}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('nti_sync')
        ->children()
            ->arrayNode('deletes')
                ->children()
                    ->scalarNode("identifier_getter")->defaultValue("getId")->end()
                ->end()
            ->end()
            ->arrayNode('last_timestamp')
                ->children()
                    ->arrayNode('ignore_properties')
                        ->arrayPrototype()
                            ->children()
                                ->scalarNode('class')->end()
                                ->scalarNode('property')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();


        // Here you should define the parameters that are allowed to
        // configure your bundle. See the documentation linked above for
        // more information on that topic.

        return $treeBuilder;
    }
}
