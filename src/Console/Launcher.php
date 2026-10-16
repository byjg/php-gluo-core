<?php

namespace ByJG\Gluo\Console;

use ByJG\Config\Config;
use ByJG\Gluo\Builder\BaseScripts;
use ByJG\Scriptify\CommandProvider;
use ByJG\Scriptify\Console\TerminalCommand;
use ByJG\Scriptify\Launcher as ScriptifyLauncher;
use ByJG\Scriptify\Scriptify;
use Exception;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Throwable;

/**
 * The `./gluo` command line entry point.
 *
 * Project commands come from the project's CommandProvider, which the ./gluo script passes
 * in, and run through Scriptify's Launcher with the Gluo container. The built-in commands
 * (migrate:*, codegen:*, openapi, terminal, install, uninstall) run through the project's
 * Builder\Scripts, and the provider cannot take their names.
 */
class Launcher extends ScriptifyLauncher
{
    /** Built-in command name => one-line description. */
    public const BUILT_IN = [
        'codegen:all' => 'Generate every component for a table (model, controller, test, ...)',
        'codegen:controller' => 'Generate the REST controller for a table',
        'codegen:model' => 'Generate the model for a table',
        'codegen:repository' => 'Generate the repository for a table (alias: codegen:repo)',
        'codegen:service' => 'Generate the service for a table',
        'codegen:test' => 'Generate the controller test for a table',
        'install' => 'Install a project command as a system service (systemd, crond, upstart, initd)',
        'migrate:create' => 'Create the migration version table (alias: migrate:install)',
        'migrate:down' => 'Migrate down to a specific version or 0',
        'migrate:reset' => 'Reset the database to base.sql and optionally migrate to a version',
        'migrate:up' => 'Migrate up to a specific version or latest',
        'migrate:update' => 'Migrate up or down to a specific version or latest',
        'migrate:version' => 'Show the current database version (alias: migrate:status)',
        'openapi' => 'Generate the OpenAPI spec from the controller attributes',
        'openapi:check' => 'Warn when the OpenAPI spec is older than its sources',
        'terminal' => 'Open an interactive PHP terminal',
        'uninstall' => 'Remove a service created by install',
    ];

    /** Alias => built-in command. */
    public const ALIASES = [
        'codegen:repo' => 'codegen:repository',
        'migrate:install' => 'migrate:create',
        'migrate:status' => 'migrate:version',
    ];

    /**
     * Group names accepted without the colon, for the composer script aliases:
     * `composer migrate -- update` runs `./gluo migrate update`.
     */
    public const COMPATIBILITY_FORMS = ['codegen', 'migrate'];

    public const TERMINAL_PRELOAD = 'templates/scriptify/scriptify.php';

    /** The launcher script, in the project root: what an installed service runs. */
    public const SCRIPT = 'gluo';

    protected string $rootDir;

    /** The project's subclass of BaseScripts, so project overrides apply to the built-ins. */
    protected string $projectScriptsClass = 'Builder\\Scripts';

    protected ?BaseScripts $scripts;

    /**
     * @param string $rootDir The project root, passed explicitly so the launcher works from
     *                        any working directory and when gluo-core is symlinked.
     * @param CommandProvider $commands The project commands.
     * @param BaseScripts|null $scripts Defaults to the project's Builder\Scripts.
     * @param resource|null $stdErr
     * @param ContainerInterface|null $container Defaults to the Gluo container
     *                        (Config::getContainer()), built when a project command needs it.
     */
    public function __construct(string $rootDir, CommandProvider $commands, ?BaseScripts $scripts = null, $stdErr = null, ?ContainerInterface $container = null)
    {
        parent::__construct($commands, $container, $stdErr);
        $this->rootDir = rtrim($rootDir, '/');
        $this->scripts = $scripts;
    }

    public static function isBuiltIn(string $name): bool
    {
        return isset(self::BUILT_IN[$name]) || isset(self::ALIASES[$name]) || in_array($name, self::COMPATIBILITY_FORMS, true);
    }

    #[\Override]
    public function isReserved(string $name): bool
    {
        return parent::isReserved($name) || self::isBuiltIn($name);
    }

    /**
     * The Gluo container: the one the Config facade uses, built on first use. Listing the
     * commands and running a built-in never build it.
     */
    #[\Override]
    protected function getContainer(): ContainerInterface
    {
        return $this->container ??= Config::getContainer();
    }

    /**
     * @throws Exception
     */
    public function getScripts(): BaseScripts
    {
        if ($this->scripts === null) {
            $class = $this->projectScriptsClass;
            $scripts = class_exists($class) ? new $class() : null;
            if (!$scripts instanceof BaseScripts) {
                throw new Exception("The built-in commands need the project's " . $class . ' class, extending ' . BaseScripts::class . '.');
            }
            $this->scripts = $scripts;
        }

        return $this->scripts;
    }

    #[\Override]
    protected function scriptName(): string
    {
        return './' . self::SCRIPT;
    }

    /**
     * Consume the global --env option and export it as APP_ENV, before anything
     * initialises Config. Accepts --env=<env>, --env <env> and -e <env>, anywhere.
     *
     * @param string[] $arguments
     * @return string[] The arguments without the option.
     * @throws Exception
     */
    #[\Override]
    protected function prepare(array $arguments): array
    {
        $env = null;
        $remaining = [];

        for ($i = 0, $count = count($arguments); $i < $count; $i++) {
            $argument = $arguments[$i];
            if (str_starts_with($argument, '--env=')) {
                $env = substr($argument, 6);
            } elseif ($argument === '--env' || $argument === '-e') {
                $env = $arguments[++$i] ?? '';
            } else {
                $remaining[] = $argument;
                continue;
            }

            if ($env === '') {
                throw new Exception('The --env option requires a value, like --env=dev.');
            }
        }

        if ($env !== null) {
            putenv("APP_ENV=$env");
        }

        return $remaining;
    }

    /**
     * @param string[] $arguments
     * @throws Throwable
     */
    #[\Override]
    protected function dispatch(string $command, array $arguments): int
    {
        // Compatibility form for the `composer migrate -- <sub>` alias. It reaches the
        // built-in subcommands only, never a project command in the same group.
        if ($command === 'migrate') {
            [$command, $arguments] = $this->expandMigrate($arguments);
        }

        if (self::isBuiltIn($command)) {
            if (isset($this->getCommands()[$command])) {
                throw $this->reservedName($command);
            }
            $command = self::ALIASES[$command] ?? $command;
            if (array_intersect($arguments, ['--help', '-h']) !== []) {
                echo $this->builtInHelp($command);
                return 0;
            }
            return $this->runBuiltIn($command, $arguments);
        }

        return parent::dispatch($command, $arguments);
    }

    /**
     * @param string[] $arguments
     * @return array{0: string, 1: string[]}
     * @throws Exception
     */
    protected function expandMigrate(array $arguments): array
    {
        foreach ($arguments as $index => $argument) {
            if (str_starts_with($argument, '-')) {
                continue;
            }
            if (!self::isBuiltIn("migrate:$argument")) {
                throw new Exception("Unknown migrate command: $argument\n\n" . $this->getScripts()->getMigrateHelp());
            }
            unset($arguments[$index]);
            return ["migrate:$argument", array_values($arguments)];
        }

        throw new Exception("Command is required.\n\n" . $this->getScripts()->getMigrateHelp());
    }

    /**
     * @param string[] $arguments
     * @throws Throwable
     */
    protected function runBuiltIn(string $command, array $arguments): int
    {
        if (str_starts_with($command, 'migrate:')) {
            $this->getScripts()->runMigrate([substr($command, 8), ...$arguments]);
            return 0;
        }
        if (str_starts_with($command, 'codegen:')) {
            $this->getScripts()->runCodeGenerator([substr($command, 8), ...$arguments]);
            return 0;
        }

        switch ($command) {
            case 'openapi':
                $this->getScripts()->runGenOpenApiDocs($arguments);
                return 0;

            case 'openapi:check':
                $scripts = $this->getScripts();
                if (!method_exists($scripts, 'runCheckOpenApi')) {
                    throw new Exception(get_class($scripts) . ' does not implement runCheckOpenApi().');
                }
                $scripts->runCheckOpenApi();
                return 0;

            // Compatibility form for the `composer codegen -- --table=x all` alias:
            // the components are in the arguments, as runCodeGenerator() takes them.
            case 'codegen':
                $this->getScripts()->runCodeGenerator($arguments);
                return 0;

            case 'install':
                return $this->install($arguments);

            case 'uninstall':
                return $this->uninstall($arguments);

            default:
                return $this->runTerminal();
        }
    }

    /**
     * `./gluo --env=<env> install [--template=<t>] [--name=<service>] [--description=<d>] <command> [args...]`
     *
     * The service runs ./gluo itself, so the Gluo container is built when it starts. Its
     * command line is written in full: the PHP binary, the absolute path to ./gluo, the
     * environment, the command and its arguments, in the project root.
     *
     * @param string[] $arguments
     * @throws Throwable
     */
    protected function install(array $arguments): int
    {
        [$options, $arguments] = $this->serviceOptions($arguments, ['template', 'name', 'description']);
        $name = array_shift($arguments) ?? throw new Exception('Which command? Usage: ./gluo --env=<env> install [--template=systemd] <command> [args...]');

        $env = (string) getenv('APP_ENV');
        if ($env === '') {
            throw new Exception('The service needs an environment: ./gluo --env=<env> install ' . $name);
        }
        $status = $this->check()[$name] ?? null;
        if ($status === null) {
            throw new Exception("Command \"$name\" is not defined. Run ./gluo list to see the available commands.");
        }
        if ($status[0] !== self::OK) {
            throw new Exception("Cannot install \"$name\": {$status[0]} {$status[1]}");
        }

        $command = PHP_BINARY . ' ' . escapeshellarg($this->rootDir . '/' . self::SCRIPT)
            . ' --env=' . escapeshellarg($env) . ' ' . $name;
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $template = $options['template'] ?? 'systemd';
        $service = $options['name'] ?? $this->serviceName($name);
        Scriptify::installCommand($service, $command, $this->rootDir, $template, $options['description'] ?? "$name ($env)");
        echo "Installed the $template service \"$service\": $command\n";

        return 0;
    }

    /**
     * `./gluo uninstall [--name=<service>] <command>`
     *
     * @param string[] $arguments
     * @throws Throwable
     */
    protected function uninstall(array $arguments): int
    {
        [$options, $arguments] = $this->serviceOptions($arguments, ['name']);
        $service = $options['name'] ?? $this->serviceName(
            array_shift($arguments) ?? throw new Exception('Which command? Usage: ./gluo uninstall <command>')
        );

        Scriptify::uninstall($service);
        echo "Removed the service \"$service\"\n";

        return 0;
    }

    /**
     * The service name of a command: the project directory and the command,
     * `shop-payment-sweep` for `payment:sweep` in `shop/`.
     */
    protected function serviceName(string $command): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(basename($this->rootDir) . '-' . $command)), '-');
    }

    /**
     * The --<name>=<value> or --<name> <value> options before the command name.
     *
     * @param string[] $arguments
     * @param string[] $names
     * @return array{0: array<string, string>, 1: string[]} [options, the rest]
     * @throws Exception
     */
    protected function serviceOptions(array $arguments, array $names): array
    {
        $options = [];
        while (($argument = $arguments[0] ?? null) !== null && str_starts_with($argument, '--')) {
            array_shift($arguments);
            [$option, $value] = explode('=', substr($argument, 2), 2) + [1 => null];
            if (!in_array($option, $names, true)) {
                throw new Exception("Unknown option --$option. Use: --" . implode(', --', $names) . '.');
            }
            $value ??= array_shift($arguments);
            if ($value === null || $value === '') {
                throw new Exception("The --$option option requires a value.");
            }
            $options[$option] = $value;
        }

        return [$options, $arguments];
    }

    /**
     * @throws Throwable
     */
    protected function runTerminal(): int
    {
        $input = ['command' => 'terminal'];

        $preload = $this->rootDir . '/' . self::TERMINAL_PRELOAD;
        if (file_exists($preload)) {
            $input['--preload'] = $preload;
        }
        $env = getenv('APP_ENV');
        if (!empty($env)) {
            $input['--env'] = ["APP_ENV=$env"];
        }

        chdir($this->rootDir);

        $application = new Application('Gluo');
        $application->addCommand(new TerminalCommand());
        $application->setAutoExit(false);

        return $application->run(new ArrayInput($input));
    }

    /**
     * @throws Exception
     */
    protected function builtInHelp(string $command): string
    {
        if (str_starts_with($command, 'migrate:')) {
            return $this->getScripts()->getMigrateHelp();
        }
        if (str_starts_with($command, 'codegen')) {
            return $this->getScripts()->getCodeGeneratorHelp();
        }

        $usage = match ($command) {
            'install' => './gluo --env=<env> install [--template=systemd|crond|upstart|initd] [--name=<service>] [--description=<text>] <command> [args...]',
            'uninstall' => './gluo uninstall [--name=<service>] <command>',
            default => "./gluo $command",
        };

        return self::BUILT_IN[$command] . ".\n\nUsage:\n  $usage\n";
    }

    #[\Override]
    protected function usage(): string
    {
        return "Usage:\n  ./gluo [--env=<env>] <command> [args...]\n  ./gluo <command> --help\n  ./gluo list [--check]\n";
    }

    #[\Override]
    protected function listCommands(): int
    {
        echo $this->usage() . "\n";

        echo "Built-in\n";
        foreach (self::BUILT_IN as $name => $description) {
            echo self::formatRow($name, $description);
        }

        echo "\n";
        $this->printCommands('Project');

        return 0;
    }

    /**
     * The environment, the built-ins with the database they need, and the project commands.
     */
    #[\Override]
    protected function report(): int
    {
        $env = getenv('APP_ENV');
        echo 'Environment: ' . (empty($env) ? '(not set)' : $env) . "\n\n";

        $builtInOk = $this->printReport('Built-in', (new Checker())->checkBuiltIn());
        echo "\n";
        $projectOk = $this->printReport('Project', $this->check());

        return $builtInOk && $projectOk ? 0 : 1;
    }
}
