<?php

// The entries of ByJG\Gluo\Http\Application, bound as in a Gluo project (config/dev/03-api.php)

use ByJG\Config\DependencyInjection as DI;
use ByJG\Gluo\Http\Application;
use ByJG\RestServer\HttpRequest;
use ByJG\RestServer\HttpResponse;
use ByJG\RestServer\Route\Route;
use ByJG\RestServer\Route\RouteList;
use ByJG\RestServer\Route\RouteListInterface;
use ByJG\RestServer\Server;

return [
    RouteListInterface::class => DI::bind(RouteList::class)
        ->withMethodCall('addRoute', [Route::get('/hello')->withClosure(function (HttpResponse $response, HttpRequest $request) {
            $response->write(['hello' => $request->query('name')]);
        })])
        ->withMethodCall('addRoute', [Route::post('/echo')->withClosure(function (HttpResponse $response, HttpRequest $request) {
            $response->write(['payload' => json_decode($request->payload(), true), 'ip' => $request->getRequestIp()]);
        })])
        ->toSingleton(),

    Server::class => DI::bind(Server::class)
        ->toSingleton(),

    Application::class => DI::bind(Application::class)
        ->withInjectedConstructor()
        ->toSingleton(),
];
