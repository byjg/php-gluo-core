<?php

namespace ByJG\Gluo\Util;

use ByJG\Authenticate\Enum\UserField;
use ByJG\Authenticate\Model\UserModel;
use ByJG\Authenticate\Model\UserToken;
use ByJG\Authenticate\Service\UsersService;
use ByJG\Config\Config;
use ByJG\Config\Exception\ConfigException;
use ByJG\Config\Exception\DependencyInjectionException;
use ByJG\Config\Exception\KeyNotFoundException;
use ByJG\Config\Exception\RunTimeException;
use ByJG\JwtWrapper\JwtWrapper;
use ByJG\RestServer\Exception\Error401Exception;
use ByJG\RestServer\Exception\Error404Exception;
use ByJG\RestServer\HttpRequest;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

class JwtContext
{
    protected static ?HttpRequest $request;

    protected static ?UserModel $user = null;

    /**
     * Fields always included in the JWT token payload.
     * Override customTokenFields() in a subclass to add project-specific fields.
     *
     * @throws ConfigException
     * @throws DependencyInjectionException
     * @throws Error401Exception
     * @throws InvalidArgumentException
     * @throws KeyNotFoundException
     * @throws ReflectionException
     * @throws RunTimeException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function createUserMetadata(UserModel|string $user, string $password = ""): UserToken|null
    {
        /** @var UsersService $usersService */
        $usersService = Config::get(UsersService::class);

        try {
            $jwtWrapper = Config::get(JwtWrapper::class);
            $tokenFields = array_merge(
                [
                    UserField::Userid,
                    UserField::Name,
                    UserField::Role->value => static::defaultRole(),
                ],
                static::customTokenFields()
            );

            if (is_string($user)) {
                $login = $user;
            } else {
                $login = $usersService->getLoginValue($user);
            }
            if (empty($login)) {
                throw new Error401Exception("Username not found");
            }

            if (empty($password)) {
                $userToken = $usersService->createInsecureAuthToken(
                    login: $login,
                    jwtWrapper: $jwtWrapper,
                    expires: static::tokenExpiry(),
                    tokenUserFields: $tokenFields
                );
            } else {
                $userToken = $usersService->createAuthToken(
                    login: $login,
                    password: $password,
                    jwtWrapper: $jwtWrapper,
                    expires: static::tokenExpiry(),
                    tokenUserFields: $tokenFields
                );
            }
        } catch (Exception $ex) {
            throw new Error401Exception($ex->getMessage());
        }

        return $userToken;
    }

    /**
     * @throws ConfigException
     * @throws ContainerExceptionInterface
     * @throws DependencyInjectionException
     * @throws InvalidArgumentException
     * @throws KeyNotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws RunTimeException
     */
    public static function createToken(array $properties = []): mixed
    {
        $jwt = Config::get(JwtWrapper::class);
        $jwtData = $jwt->createJwtData($properties, 60 * 60 * 24 * 7);
        return $jwt->generateToken($jwtData);
    }

    public static function setRequest(HttpRequest $request): void
    {
        self::$request = $request;
    }

    /**
     * Clear the stored request and cached user. Call between requests in
     * long-running runtimes (workers, Swoole/RoadRunner) and in test setUp.
     */
    public static function reset(): void
    {
        self::$request = null;
        self::$user = null;
    }

    protected static function getRequestParam(string $value): ?string
    {
        if (isset(self::$request)) {
            $data = (array)self::$request->attribute("jwt.data");
            if (isset($data[$value])) {
                return $data[$value];
            }
        }
        return null;
    }

    public static function getUser(): UserModel
    {
        if (!empty(static::$user)) {
            return static::$user;
        }

        $userId = self::getUserId();
        if (empty($userId)) {
            throw new Error404Exception('User not found');
        }

        /** @var UsersService $usersService */
        $usersService = Config::get(UsersService::class);
        $user = $usersService->getById($userId);

        if (empty($user)) {
            throw new Error404Exception('User not found');
        }

        /** @var UserModel $user */
        return $user;
    }

    public static function getUserId(): ?string
    {
        return self::getRequestParam("userid");
    }

    public static function getRole(): ?string
    {
        return self::getRequestParam("role");
    }

    public static function getName(): ?string
    {
        return self::getRequestParam("name");
    }

    /**
     * Override to add extra fields to the JWT token payload.
     */
    protected static function customTokenFields(): array
    {
        return [];
    }

    /**
     * Override to change token expiry (in seconds).
     */
    protected static function tokenExpiry(): int
    {
        return 3600;
    }

    /**
     * Override to change the default role assigned when a user has no role set.
     */
    protected static function defaultRole(): string
    {
        return 'user';
    }
}