<?php

namespace ByJG\Gluo\Console;

use ByJG\Gluo\Builder\BaseScripts;
use ByJG\Scriptify\Console\TerminalCommand;
use ByJG\Scriptify\Runner;
use Exception;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Throwable;

/**
 * The `./gluo` command line entry point.
 *
 * Built-in commands (migrate:*, openapi, codegen, terminal) run through the project's
 * Builder\Scripts. Project commands are read from config/commands.php, which maps a short
 * name to a Scriptify target, and run through Scriptify with the Gluo container.
 */
class Launcher
{
    /** Built-in command name => one-line description. */
    public const BUILT_IN = [
        'codegen:all' => 'Generate every component for a table (model, controller, test, ...)',
        'codegen:controller' => 'Generate the REST controller for a table',
        'codegen:model' => 'Generate the model for a table',
        'codegen:repository' => 'Generate the repository for a table (alias: codegen:repo)',
        'codegen:service' => 'Generate the service for a table',
        'codegen:test' => 'Generate the controller test for a table',
        'migrate:create' => 'Create the migration version table (alias: migrate:install)',
        'migrate:down' => 'Migrate down to a specific version or 0',
        'migrate:reset' => 'Reset the database to base.sql and optionally migrate to a version',
        'migrate:up' => 'Migrate up to a specific version or latest',
        'migrate:update' => 'Migrate up or down to a specific version or latest',
        'migrate:version' => 'Show the current database version (alias: migrate:status)',
        'openapi' => 'Generate the OpenAPI spec from the controller attributes',
        'openapi:check' => 'Warn when the OpenAPI spec is older than its sources',
        'terminal' => 'Open an interactive PHP terminal',
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

    public const BOOTSTRAP_FILE = 'config/scriptify-bootstrap.php';
    public const COMMANDS_FILE = 'config/commands.php';
    public const TERMINAL_PRELOAD = 'templates/scriptify/scriptify.php';

    protected string $rootDir;

    /** The project's subclass of BaseScripts, so project overrides apply to the built-ins. */
    protected string $projectScriptsClass = 'Builder\\Scripts';

    protected ?BaseScripts $scripts;

    /** @var resource */
    protected $stdErr;

    protected ?ContainerInterface $container = null;

    /**
     * @param string $rootDir The project root, passed explicitly so the launcher works from
     *                        any working directory and when gluo-core is symlinked.
     * @param BaseScripts|null $scripts Defaults to the project's Builder\Scripts.
     * @param resource|null $stdErr
     */
    public function __construct(string $rootDir, ?BaseScripts $scripts = null, $stdErr = null)
    {
        $this->rootDir = rtrim($rootDir, '/');
        $this->scripts = $scripts;
        $this->stdErr = $stdErr ?? STDERR;
    }

    /**
     * @param string[] $argv The raw argument vector, script name included.
     * @return int The process exit code.
     */
    public function run(array $argv): int
    {
        $verbose = false;

        try {
            $arguments = $this->applyEnvironment(array_slice(array_values($argv), 1));
            $verbose = array_intersect($arguments, ['-v', '-vv', '-vvv']) !== [];
            $command = array_shift($arguments) ?? 'list';

            return $this->dispatch($command, $arguments);
        } catch (Throwable $ex) {
            fwrite($this->stdErr, $ex->getMessage() . "\n");
            if ($verbose) {
                fwrite($this->stdErr, "\n" . $ex->getTraceAsString() . "\n");
            }
            return 1;
        }
    }

    public static function isBuiltIn(string $name): bool
    {
        return isset(self::BUILT_IN[$name]) || isset(self::ALIASES[$name]) || in_array($name, self::COMPATIBILITY_FORMS, true);
    }

    public static function isValidName(string $name): bool
    {
        return preg_match('/^[a-z0-9:-]+$/', $name) === 1;
    }

    /**
     * The project command registry: command name => Scriptify target.
     *
     * @return array<array-key, mixed>
     * @throws Exception
     */
    public function getProjectCommands(): array
    {
        $file = $this->rootDir . '/' . self::COMMANDS_FILE;
        if (!file_exists($file)) {
            return [];
        }

        $commands = require $file;
        if (!is_array($commands)) {
            throw new Exception(self::COMMANDS_FILE . ' must return an array of command name => "Class::method".');
        }
        ksort($commands);

        return $commands;
    }

    /**
     * The Gluo PSR-11 container, as returned by the Scriptify bootstrap file.
     *
     * @throws Exception
     */
    public function getContainer(): ContainerInterface
    {
        if ($this->container !== null) {
            return $this->container;
        }

        $file = $this->rootDir . '/' . self::BOOTSTRAP_FILE;
        if (!file_exists($file)) {
            throw new Exception(self::BOOTSTRAP_FILE . ' was not found. It must return the PSR-11 container.');
        }

        $container = require $file;
        if (!$container instanceof ContainerInterface) {
            throw new Exception(self::BOOTSTRAP_FILE . ' must return a PSR-11 container.');
        }

        return $this->container = $container;
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

    /**
     * Consume the global --env option and export it as APP_ENV, before anything
     * initialises Config. Accepts --env=<env>, --env <env> and -e <env>, anywhere.
     *
     * @param string[] $arguments
     * @return string[] The arguments without the option.
     * @throws Exception
     */
    protected function applyEnvironment(array $arguments): array
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
    protected function dispatch(string $command, array $arguments): int
    {
        if (in_array($command, ['list', '--help', '-h'], true)) {
            return in_array('--check', $arguments, true)
                ? (new Checker($this))->report()
                : $this->listCommands();
        }

        // Compatibility form for the `composer migrate -- <sub>` alias. It reaches the
        // built-in subcommands only, never a project command in the same group.
        if ($command === 'migrate') {
            [$command, $arguments] = $this->expandMigrate($arguments);
        }

        $projectCommands = $this->getProjectCommands();
        $wantsHelp = array_intersect($arguments, ['--help', '-h']) !== [];

        if (self::isBuiltIn($command)) {
            if (isset($projectCommands[$command])) {
                throw new Exception("\"$command\" is a built-in command and cannot be used as a project command name. Rename it in " . self::COMMANDS_FILE . '.');
            }
            $command = self::ALIASES[$command] ?? $command;
            if ($wantsHelp) {
                echo $this->builtInHelp($command);
                return 0;
            }
            return $this->runBuiltIn($command, $arguments);
        }

        if (!isset($projectCommands[$command])) {
            throw new Exception("Command \"$command\" is not defined. Run ./gluo list to see the available commands.");
        }
        if (!self::isValidName($command)) {
            throw new Exception("Invalid command name \"$command\": only lowercase letters, digits, ':' and '-' are allowed.");
        }
        $target = $projectCommands[$command];
        if (!is_string($target) || !str_contains($target, '::')) {
            throw new Exception("Invalid target for \"$command\" in " . self::COMMANDS_FILE . ': expected "Namespace/Class::method".');
        }

        // -v belongs to the launcher (stack trace on failure), not to the method.
        $arguments = array_values(array_diff($arguments, ['-v', '-vv', '-vvv', '--help', '-h']));

        $runner = new Runner($target, $arguments, false, $this->getContainer());
        if ($wantsHelp) {
            $runner->showDocs("./gluo $command");
            echo "\n";
            return 0;
        }
        $runner->execute();

        return 0;
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

            default:
                return $this->runTerminal();
        }
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

        return self::BUILT_IN[$command] . ".\n\nUsage:\n  ./gluo $command\n";
    }

    /**
     * @throws Exception
     */
    protected function listCommands(): int
    {
        echo "Usage:\n  ./gluo [--env=<env>] <command> [args...]\n  ./gluo <command> --help\n  ./gluo list [--check]\n\n";

        echo "Built-in\n";
        foreach (self::BUILT_IN as $name => $description) {
            echo self::formatRow($name, $description);
        }

        echo "\nProject\n";
        $projectCommands = $this->getProjectCommands();
        foreach ($projectCommands as $name => $target) {
            echo self::formatRow((string) $name, is_string($target) ? $target : '(invalid target)');
        }
        if ($projectCommands === []) {
            echo "  (none: add them to " . self::COMMANDS_FILE . ")\n";
        }

        return 0;
    }

    public static function formatRow(string ...$columns): string
    {
        $row = '  ' . str_pad((string) array_shift($columns), 22);
        foreach ($columns as $column) {
            $row .= ' ' . $column;
        }

        return rtrim($row) . "\n";
    }
}
