<?php

namespace ByJG\Gluo\Attribute;

use Attribute;
use ByJG\Gluo\Util\JwtContext;
use ByJG\RestServer\HttpRequest;
use ByJG\RestServer\HttpResponse;
use Override;

#[Attribute(Attribute::TARGET_METHOD)]
class RequireAuthenticated extends \ByJG\RestServer\Attributes\RequireAuthenticated
{
    #[Override]
    public function processBefore(HttpResponse $response, HttpRequest $request): void
    {
        parent::processBefore($response, $request);
        JwtContext::setRequest($request);
    }
}