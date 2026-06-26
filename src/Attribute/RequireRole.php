<?php

namespace ByJG\Gluo\Attribute;

use Attribute;
use ByJG\Gluo\Util\JwtContext;
use ByJG\RestServer\HttpRequest;
use ByJG\RestServer\HttpResponse;
use Override;

#[Attribute(Attribute::TARGET_METHOD)]
class RequireRole extends \ByJG\RestServer\Attributes\RequireRole
{
    public function __construct(string $role)
    {
        parent::__construct($role, 'jwt.data', "role");
    }

    #[Override]
    public function processBefore(HttpResponse $response, HttpRequest $request): void
    {
        parent::processBefore($response, $request);
        JwtContext::setRequest($request);
    }
}