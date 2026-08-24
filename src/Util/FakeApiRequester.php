<?php

namespace ByJG\Gluo\Util;

use ByJG\ApiTools\AbstractRequester;
use ByJG\Config\Config;
use ByJG\Config\Exception\ConfigException;
use ByJG\Config\Exception\DependencyInjectionException;
use ByJG\Config\Exception\KeyNotFoundException;
use ByJG\Config\Exception\RunTimeException;
use ByJG\RestServer\Exception\Error404Exception;
use ByJG\RestServer\Exception\Error405Exception;
use ByJG\RestServer\Exception\Error422Exception;
use ByJG\RestServer\Exception\Error520Exception;
use ByJG\RestServer\Exception\OperationIdInvalidException;
use ByJG\RestServer\Middleware\JwtMiddleware;
use ByJG\RestServer\MockServer;
use ByJG\RestServer\Route\OpenApiRouteList;
use ByJG\WebRequest\Exception\MessageException;
use ByJG\WebRequest\Exception\RequestException;
use ByJG\WebRequest\MockClient;
use Override;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

class FakeApiRequester extends AbstractRequester
{
    /**
     * @throws ConfigException
     * @throws DependencyInjectionException
     * @throws Error404Exception
     * @throws Error405Exception
     * @throws Error520Exception
     * @throws InvalidArgumentException
     * @throws KeyNotFoundException
     * @throws ReflectionException
     * @throws RequestException
     * @throws RunTimeException
     * @throws Error422Exception
     * @throws OperationIdInvalidException
     * @throws MessageException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    #[Override]
    protected function handleRequest(RequestInterface $request): ResponseInterface
    {
        $logger = Config::has(LoggerInterface::class) ? Config::get(LoggerInterface::class) : new NullLogger();
        $mock = new MockServer($logger);

        // This harness builds its own server, so it does not inherit anything configured
        // on the application's Server binding. Without this, a controller that declares
        // constructor dependencies would work in production and fail only under test.
        //
        // Lenient on purpose: a project part-way through registering its controllers must
        // still be able to run its tests. Controllers that do declare dependencies still
        // fail loudly here, because the fallback `new` cannot satisfy them.
        $mock->withContainer(Config::getContainer(), allowUnregistered: true);

        if (Config::has(JwtMiddleware::class)) {
            $mock->withMiddleware(Config::get(JwtMiddleware::class));
        }
        $mock->withRequestObject($request);
        $mock->handle(Config::get(OpenApiRouteList::class), false, false);

        $httpClient = new MockClient($mock->getPsr7Response());
        return $httpClient->sendRequest($request);
    }
}