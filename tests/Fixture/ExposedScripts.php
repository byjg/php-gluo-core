<?php

namespace ByJGTest\Gluo\Fixture;

use ByJG\Gluo\Builder\BaseScripts;

class ExposedScripts extends BaseScripts
{
    public function setWorkdir(string $dir): void
    {
        $this->workdir = $dir;
    }

    public function callGetAppNamespace(): string
    {
        return $this->getAppNamespace();
    }

    public function callGetCodegenTemplatePath(): string
    {
        return $this->getCodegenTemplatePath();
    }

    public function callExtractArguments(array $arguments, bool $hasCmd = true): array
    {
        return $this->extractArguments($arguments, $hasCmd);
    }

    public function callGetEnvironment(array $argumentList): string
    {
        return $this->getEnvironment($argumentList, 'help text');
    }

    public function callAddToConfig(string $configFile, string $className, string $namespace): void
    {
        ob_start();
        try {
            $this->addToConfig($configFile, $className, $namespace);
        } finally {
            ob_end_clean();
        }
    }
}
