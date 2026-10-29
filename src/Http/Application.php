<?php

namespace ByJG\Gluo\Http;

use ByJG\RestServer\ErrorHandler;
use ByJG\RestServer\Route\RouteListInterface;
use ByJG\RestServer\Server;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * The HTTP entry point of a Gluo application, bound in the configuration
 * (config/<env>/03-api.php) with the Server and the routes (RouteListInterface), and used by public/app.php:
 *
 *     Config::get(Application::class)->handle();
 *
 * handle() serves one request. PHP-FPM serves one request per process. A persistent worker
 * (RoadRunner, Swoole, a CLI worker) gets the Application once and calls handle() with each
 * request, as a PSR-7 request, since php://input is not the request body there.
 */
final class Application
{
    public function __construct(
        private readonly Server $server,
        private readonly RouteListInterface $routes,
    ) {
    }

    /**
     * @param ServerRequestInterface|null $request null handles the request PHP's globals describe
     */
    public function handle(?ServerRequestInterface $request = null): void
    {
        if ($request === null) {
            // The exception handler RestServer registers renders the errors
            $this->server->handle($this->routes);
            return;
        }

        try {
            $this->server->withRequestObject($request)->handle($this->routes);
        } catch (Throwable $exception) {
            // Outside PHP-FPM an exception reaches the caller, not the registered handler
            ErrorHandler::getInstance()->handleException($exception);
        }
    }
}
