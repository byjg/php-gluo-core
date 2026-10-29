<?php

namespace Tests\Http;

use ByJG\Config\Config;
use ByJG\Config\Definition;
use ByJG\Config\Environment;
use ByJG\Gluo\Http\Application;
use ByJG\RestServer\ErrorHandler;
use ByJG\Util\Uri;
use ByJG\WebRequest\Psr7\MemoryStream;
use ByJG\WebRequest\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

class ApplicationTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        Config::reset();
        Config::initialize(
            (new Definition())
                ->withBaseDir(__DIR__ . '/../Fixture/config')
                ->addEnvironment(new Environment('http')),
            'http'
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET = [];
        ErrorHandler::getInstance()->unregister();
        Config::reset();
    }

    /**
     * The output of one request; RestServer leaves its output buffer open
     */
    private function capture(callable $request): string
    {
        $level = ob_get_level();
        ob_start();
        $request();
        $output = '';
        while (ob_get_level() > $level) {
            $output = (string)ob_get_clean() . $output;
        }
        return $output;
    }

    private function psr7Request(string $method, string $path, string $body = ''): ServerRequestInterface
    {
        return (new ServerRequest(Uri::getInstance("http://localhost$path"), ['REMOTE_ADDR' => '10.0.0.5']))
            ->withMethod($method)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new MemoryStream($body));
    }

    public function testHandlesTheGlobalsWithoutARequest(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/hello?name=fpm', 'QUERY_STRING' => 'name=fpm'];
        $_GET = ['name' => 'fpm'];

        $output = $this->capture(fn() => Config::get(Application::class)->handle());

        $this->assertEquals(['hello' => 'fpm'], json_decode($output, true));
    }

    public function testHandlesAPsr7Request(): void
    {
        $output = $this->capture(fn() => Config::get(Application::class)->handle($this->psr7Request('POST', '/echo', '{"name":"John"}')));

        $this->assertEquals(['payload' => ['name' => 'John'], 'ip' => '10.0.0.5'], json_decode($output, true));
    }

    /**
     * A worker keeps one Application, and the Server is a singleton: nothing of one request
     * reaches the next
     */
    public function testConsecutiveRequests(): void
    {
        $application = Config::get(Application::class);

        $first = $this->capture(fn() => $application->handle($this->psr7Request('POST', '/echo', '{"n":1}')));
        $second = $this->capture(fn() => $application->handle($this->psr7Request('POST', '/echo', '{"n":2}')));

        $this->assertEquals(['payload' => ['n' => 1], 'ip' => '10.0.0.5'], json_decode($first, true));
        $this->assertEquals(['payload' => ['n' => 2], 'ip' => '10.0.0.5'], json_decode($second, true));
    }

    /**
     * RestServer throws its HTTP errors; for a PSR-7 request the Application renders them,
     * as the exception handler RestServer registers does in PHP-FPM
     */
    public function testRendersTheErrorOfAPsr7Request(): void
    {
        $output = $this->capture(fn() => Config::get(Application::class)->handle($this->psr7Request('GET', '/missing')));

        $this->assertEquals(
            ['error' => ['type' => 'Error 404', 'message' => "Route '/missing' not found"]],
            json_decode($output, true)
        );
    }
}
