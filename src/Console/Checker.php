<?php

namespace ByJG\Gluo\Console;

use ByJG\AnyDataset\Db\Factory;
use ByJG\Config\Config;
use ByJG\Util\Uri;
use Throwable;

/**
 * The built-in rows of `./gluo list --check`: what each built-in needs, the database for
 * migrate:* and codegen:*, opened once per run. The project rows are Scriptify's
 * Launcher::check().
 */
class Checker
{
    public const OK = 'OK';

    /** Shortest password replaced literally in a message; see maskPassword() */
    public const MASK_MIN_LENGTH = 4;

    /** @var array{0: string, 1: string}|null */
    protected ?array $databaseStatus = null;

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

    protected static function oneLine(string $message): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $message));
    }

    /**
     * Masks the connection password in an error message. A password inside a URL
     * (`://user:pw@`, `://:pw@`) is always masked. Elsewhere the password is replaced literally only when
     * it has at least MASK_MIN_LENGTH characters: a short one such as "0" would replace every
     * match in the message (`127.0.0.1` would become `127.****.****.1`).
     */
    protected static function maskPassword(string $message, string $connection): string
    {
        $password = $connection === '' ? null : (new Uri($connection))->getPassword();
        if ($password !== null && strlen($password) >= self::MASK_MIN_LENGTH) {
            $message = str_replace([$password, rawurlencode($password)], '****', $message);
        }

        return (string) preg_replace('~://([^:/@\s]*):[^@\s]+@~', '://$1:****@', $message);
    }
}
