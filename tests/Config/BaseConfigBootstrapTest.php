<?php

namespace ByJGTest\Gluo\Config;

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

    public function testCacheOnlyOnStagingAndProd(): void
    {
        $definition = $this->bootstrap()->loadDefinition();

        $this->assertNull($definition->getConfigObject('dev')->getCacheInterface());
        $this->assertNull($definition->getConfigObject('test')->getCacheInterface());
        $this->assertNotNull($definition->getConfigObject('staging')->getCacheInterface());
        $this->assertNotNull($definition->getConfigObject('prod')->getCacheInterface());
    }
}
