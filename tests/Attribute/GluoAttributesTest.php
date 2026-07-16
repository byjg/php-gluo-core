<?php

namespace ByJGTest\Gluo\Attribute;

use ByJG\Gluo\Attribute\RequireAuthenticated;
use ByJG\Gluo\Attribute\RequireRole;
use ByJG\Gluo\Util\JwtContext;
use ByJG\RestServer\Exception\Error401Exception;
use ByJG\RestServer\Exception\Error403Exception;
use ByJG\RestServer\HttpRequest;
use ByJG\RestServer\HttpResponse;
use ByJG\RestServer\Middleware\JwtMiddleware;
use PHPUnit\Framework\TestCase;

class GluoAttributesTest extends TestCase
{
    protected function setUp(): void
    {
        JwtContext::reset();
    }

    protected function authenticatedRequest(array $jwtData): HttpRequest
    {
        return new HttpRequest([], [], [], [], [], [
            JwtMiddleware::JWT_PARAM_PARSE_STATUS => JwtMiddleware::JWT_SUCCESS,
            'jwt.data' => $jwtData,
        ]);
    }

    protected function anonymousRequest(): HttpRequest
    {
        return new HttpRequest([], [], [], [], [], []);
    }

    public function testRequireAuthenticatedRejectsAnonymousRequest(): void
    {
        $this->expectException(Error401Exception::class);

        (new RequireAuthenticated())->processBefore(
            new HttpResponse(),
            $this->anonymousRequest()
        );
    }

    public function testRequireAuthenticatedStoresRequestInJwtContext(): void
    {
        $request = $this->authenticatedRequest(['userid' => 'abc-123', 'role' => 'user']);

        (new RequireAuthenticated())->processBefore(
            new HttpResponse(),
            $request
        );

        $this->assertSame('abc-123', JwtContext::getUserId());
    }

    public function testRequireRoleRejectsAnonymousRequest(): void
    {
        $this->expectException(Error401Exception::class);

        (new RequireRole('admin'))->processBefore(
            new HttpResponse(),
            $this->anonymousRequest()
        );
    }

    public function testRequireRoleRejectsWrongRole(): void
    {
        $this->expectException(Error403Exception::class);

        (new RequireRole('admin'))->processBefore(
            new HttpResponse(),
            $this->authenticatedRequest(['userid' => 'abc-123', 'role' => 'user'])
        );
    }

    public function testRequireRoleAcceptsMatchingRoleAndStoresContext(): void
    {
        $request = $this->authenticatedRequest(['userid' => 'abc-123', 'role' => 'admin']);

        (new RequireRole('admin'))->processBefore(
            new HttpResponse(),
            $request
        );

        $this->assertSame('abc-123', JwtContext::getUserId());
        $this->assertSame('admin', JwtContext::getRole());
    }
}
