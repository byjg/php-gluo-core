<?php

namespace ByJG\Gluo\Config;

use ByJG\Cache\Psr16\FileSystemCacheEngine;
use ByJG\Config\ConfigInitializeInterface;
use ByJG\Config\Definition;
use ByJG\Config\Environment;
use Override;

abstract class BaseConfigBootstrap implements ConfigInitializeInterface
{
    #[Override]
    public function loadDefinition(?string $env = null): Definition
    {
        $dev = Environment::create('dev');
        $test = Environment::create('test')
            ->inheritFrom($dev);
        $staging = Environment::create('staging')
            ->inheritFrom($dev)
            ->withCache(new FileSystemCacheEngine());
        $prod = Environment::create('prod')
            ->inheritFrom($staging, $dev)
            ->withCache(new FileSystemCacheEngine());

        $definition = (new Definition())
            ->addEnvironment($dev)
            ->addEnvironment($test)
            ->addEnvironment($staging)
            ->addEnvironment($prod);

        $this->configureDefinition($definition);

        return $definition;
    }

    /**
     * Override to add config directories, OS environment variables, or custom environments.
     * Called after all default environments are registered.
     *
     * Note: addConfigDirectory() will be available once byjg/php-config PR #17 is merged,
     * enabling package-default configs with scaffold overrides.
     */
    protected function configureDefinition(Definition $definition): void
    {
        // Default: expose TAG_VERSION and TAG_COMMIT from OS environment
        $definition->withOSEnvironment(['TAG_VERSION', 'TAG_COMMIT']);
    }
}