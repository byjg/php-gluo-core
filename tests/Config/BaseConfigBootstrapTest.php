<?php

namespace Tests\Config;

use ByJG\Cache\Psr16\ArrayCacheEngine;
use ByJG\Config\Definition;
use ByJG\Gluo\Config\BaseConfigBootstrap;
use PHPUnit\Framework\TestCase;

class BaseConfigBootstrapTest extends TestCase
{
    protected function bootstrap(): BaseConfigBootstrap
    {
        return new class extends BaseConfigBootstrap {
        };
    }

    public function testLoadDefinitionRegistersDefaultEnvironments(): void
    {
        $definition = $this->bootstrap()->loadDefinition();

        $this->assertInstanceOf(Definition::class, $definition);
        foreach (['dev', 'test', 'staging', 'prod'] as $env) {
            $this->assertSame($env, $definition->getConfigObject($env)->getName());
        }
    }

    public function testEnvironmentInheritanceChain(): void
    {
        $definition = $this->bootstrap()->loadDefinition();

        $inheritNames = fn (string $env) => array_map(
            fn ($e) => $e->getName(),
            $definition->getConfigObject($env)->getInheritFrom()
        );

        $this->assertSame([], $inheritNames('dev'));
        $this->assertSame(['dev'], $inheritNames('test'));
        $this->assertSame(['dev'], $inheritNames('staging'));
        $this->assertSame(['staging', 'dev'], $inheritNames('prod'));
    }

    public function testNoConfigCacheByDefault(): void
    {
        $definition = $this->bootstrap()->loadDefinition();

        foreach (['dev', 'test', 'staging', 'prod'] as $env) {
            $this->assertNull($definition->getConfigObject($env)->getCacheInterface(), $env);
        }
    }

    public function testProjectEnablesConfigCache(): void
    {
        $cache = new ArrayCacheEngine();
        $definition = (new class ($cache) extends BaseConfigBootstrap {
            public function __construct(private ArrayCacheEngine $cache)
            {
            }

            #[\Override]
            protected function configureDefinition(Definition $definition): void
            {
                parent::configureDefinition($definition);
                $definition->getConfigObject('prod')->withCache($this->cache);
            }
        })->loadDefinition();

        $this->assertSame($cache, $definition->getConfigObject('prod')->getCacheInterface());
        $this->assertNull($definition->getConfigObject('staging')->getCacheInterface());
    }
}
