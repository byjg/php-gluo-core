<?php

namespace ByJG\Gluo\Attribute;

use Attribute;
use ByJG\Gluo\Util\OpenApiContext;
use ByJG\Config\Exception\ConfigException;
use ByJG\Config\Exception\ConfigNotFoundException;
use ByJG\Config\Exception\DependencyInjectionException;
use ByJG\Config\Exception\KeyNotFoundException;
use ByJG\RestServer\Attributes\BeforeRouteInterface;
use ByJG\RestServer\Exception\Error400Exception;
use ByJG\RestServer\HttpRequest;
use ByJG\RestServer\HttpResponse;
use ByJG\XmlUtil\Exception\FileException;
use ByJG\XmlUtil\Exception\XmlUtilException;
use Override;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

#[Attribute(Attribute::TARGET_METHOD)]
class ValidateRequest implements BeforeRouteInterface
{
    protected static mixed $payload = null;

    protected bool $preserveNullValues;

    /**
     * @param bool $preserveNullValues If false, null values are removed from payload (default: false)
     */
    public function __construct(bool $preserveNullValues = false)
    {
        $this->preserveNullValues = $preserveNullValues;
    }

    /**
     * @throws ConfigException
     * @throws ConfigNotFoundException
     * @throws DependencyInjectionException
     * @throws Error400Exception
     * @throws InvalidArgumentException
     * @throws KeyNotFoundException
     * @throws ReflectionException
     * @throws XmlUtilException
     * @throws FileException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    #[Override]
    public function processBefore(HttpResponse $response, HttpRequest $request): void
    {
        self::$payload = OpenApiContext::validateRequest($request, $this->preserveNullValues);
    }

    public static function getPayload(): mixed
    {
        return self::$payload;
    }
}