<?php

namespace ByJGTest\Gluo\Util;

use ByJG\Gluo\Util\JwtContext;
use ByJG\RestServer\Exception\Error404Exception;
use ByJG\RestServer\HttpRequest;
use PHPUnit\Framework\TestCase;

class JwtContextTest extends TestCase
{
    protected function setUp(): void
    {
        JwtContext::reset();
    }

    protected function tearDown(): void
    {
        JwtContext::reset();
    }

    protected function requestWithJwtData(?array $jwtData): HttpRequest
    {
        $param = $jwtData === null ? [] : ['jwt.data' => $jwtData];
        return new HttpRequest([], [], [], [], [], $param);
    }

    public function testReadsClaimsFromRequest(): void
    {
        JwtContext::setRequest($this->requestWithJwtData([
            'userid' => 'abc-123',
            'role' => 'admin',
            'name' => 'John Doe',
        ]));

        $this->assertSame('abc-123', JwtContext::getUserId());
        $this->assertSame('admin', JwtContext::getRole());
        $this->assertSame('John Doe', JwtContext::getName());
    }

    public function testReturnsNullForMissingClaims(): void
    {
        JwtContext::setRequest($this->requestWithJwtData(['userid' => 'abc-123']));

        $this->assertSame('abc-123', JwtContext::getUserId());
        $this->assertNull(JwtContext::getRole());
        $this->assertNull(JwtContext::getName());
    }

    public function testReturnsNullWhenJwtDataAbsent(): void
    {
        JwtContext::setRequest($this->requestWithJwtData(null));

        $this->assertNull(JwtContext::getUserId());
        $this->assertNull(JwtContext::getRole());
        $this->assertNull(JwtContext::getName());
    }

    public function testReturnsNullWhenNoRequestWasSet(): void
    {
        $this->assertNull(JwtContext::getUserId());
        $this->assertNull(JwtContext::getRole());
        $this->assertNull(JwtContext::getName());
    }

    public function testGetUserThrows404WithoutUserId(): void
    {
        JwtContext::setRequest($this->requestWithJwtData(null));

        $this->expectException(Error404Exception::class);
        $this->expectExceptionMessage('User not found');

        JwtContext::getUser();
    }
}
