<?php

namespace ByJG\Gluo\Console;

use ByJG\AnyDataset\Db\Factory;
use ByJG\Config\Config;
use ByJG\Scriptify\Runner;
use ByJG\Util\Uri;
use ReflectionMethod;
use Throwable;

/**
 * `./gluo list --check`: a status per command, without running any of them.
 *
 * Project commands are never built: the check uses reflection and the container's has()
 * only, so listing does not open the connections a constructor would. The one exception
 * is the database, for the built-ins that need it, and it is opened once per run.
 */
class Checker
{
    public const OK = 'OK';

    /** @var array{0: string, 1: string}|null */
    protected ?array $databaseStatus = null;

    public function __construct(protected Launcher $launcher)
    {
    }

    /**
     * Print the report. Returns 0 when every command is OK, 1 otherwise.
     */
    public function report(): int
    {
        $env = getenv('APP_ENV');
        echo 'Environment: ' . (empty($env) ? '(not set)' : $env) . "\n";

        $allOk = true;
        foreach (['Built-in' => $this->checkBuiltIn(), 'Project' => $this->checkProject()] as $title => $rows) {
            echo "\n$title\n";
            foreach ($rows as $name => [$status, $detail]) {
                echo Launcher::formatRow((string) $name, str_pad($status, 17), $detail);
                $allOk = $allOk && $status === self::OK;
            }
            if ($rows === []) {
                echo "  (none)\n";
            }
        }

        return $allOk ? 0 : 1;
    }

    /**
     * @return array<string, array{0: string, 1: string}> command => [status, detail]
     */
    public function checkBuiltIn(): array
    {
        $result = [];
        foreach (array_keys(Launcher::BUILT_IN) as $name) {
            if (str_starts_with($name, 'codegen:') || str_starts_with($name, 'migrate:')) {
                $result[$name] = $this->checkDatabase();
            } elseif ($name === 'terminal' && !extension_loaded('readline')) {
                $result[$name] = ['MISSING_EXTENSION', 'readline'];
            } else {
                $result[$name] = [self::OK, ''];
            }
        }

        return $result;
    }

    /**
     * Environment, configuration and database connectivity, in that order; the first
     * failure is the status. Evaluated once and shared by every command that needs it.
     *
     * @return array{0: string, 1: string}
     */
    protected function checkDatabase(): array
    {
        if ($this->databaseStatus !== null) {
            return $this->databaseStatus;
        }

        if (empty(getenv('APP_ENV'))) {
            return $this->databaseStatus = ['NO_ENV', 'Set APP_ENV or use --env'];
        }

        try {
            $hasConnection = Config::has('DBDRIVER_CONNECTION');
        } catch (Throwable $ex) {
            return $this->databaseStatus = ['CONFIG_ERROR', self::oneLine($ex->getMessage())];
        }
        if (!$hasConnection) {
            return $this->databaseStatus = ['NO_DB_CONFIG', 'DBDRIVER_CONNECTION is not defined'];
        }

        $connection = '';
        try {
            $connection = (string) Config::get('DBDRIVER_CONNECTION');
            // Connects in the constructor; no query is sent and the version table is not touched.
            Factory::getDbInstance($connection)->disconnect();
        } catch (Throwable $ex) {
            return $this->databaseStatus = ['DB_UNREACHABLE', self::maskPassword(self::oneLine($ex->getMessage()), $connection)];
        }

        return $this->databaseStatus = [self::OK, ''];
    }

    /**
     * @return array<array-key, array{0: string, 1: string}> command => [status, detail]
     */
    public function checkProject(): array
    {
        try {
            $commands = $this->launcher->getProjectCommands();
        } catch (Throwable $ex) {
            return [Launcher::COMMANDS_FILE => ['ERROR', self::oneLine($ex->getMessage())]];
        }
        if ($commands === []) {
            return [];
        }

        // Initialised once, for has(). A failure here is reported on every command.
        try {
            $container = $this->launcher->getContainer();
        } catch (Throwable $ex) {
            return array_fill_keys(array_keys($commands), ['ERROR', self::oneLine($ex->getMessage())]);
        }

        $result = [];
        foreach ($commands as $name => $target) {
            $detail = is_string($target) ? $target : '';
            try {
                $result[$name] = [$this->projectStatus((string) $name, $target, $container), $detail];
            } catch (Throwable $ex) {
                $result[$name] = ['ERROR', self::oneLine($ex->getMessage())];
            }
        }

        return $result;
    }

    protected function projectStatus(string $name, mixed $target, \Psr\Container\ContainerInterface $container): string
    {
        if (!Launcher::isValidName($name)) {
            return 'INVALID_NAME';
        }
        if (Launcher::isBuiltIn($name)) {
            return 'RESERVED';
        }
        if (!is_string($target) || preg_match('~^[^:\s]+::[A-Za-z_]\w*$~', $target) !== 1) {
            return 'INVALID_TARGET';
        }

        [$class, $method] = explode('::', ltrim(Runner::normalize($target), '\\'));
        if (!class_exists($class)) {
            return 'MISSING_CLASS';
        }
        if (!method_exists($class, $method)) {
            return 'MISSING_METHOD';
        }
        if (!(new ReflectionMethod($class, $method))->isPublic()) {
            return 'NOT_PUBLIC';
        }
        if (!$container->has($class)) {
            return 'NOT_IN_CONTAINER';
        }

        return self::OK;
    }

    protected static function oneLine(string $message): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $message));
    }

    protected static function maskPassword(string $message, string $connection): string
    {
        $password = $connection === '' ? '' : (new Uri($connection))->getPassword();
        if (!empty($password)) {
            $message = str_replace([$password, rawurlencode($password)], '****', $message);
        }

        return (string) preg_replace('~://([^:/@\s]+):[^@\s]+@~', '://$1:****@', $message);
    }
}
