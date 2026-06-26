<?php

namespace ByJG\Gluo\Util;

use ByJG\ApiTools\Base\Schema;
use ByJG\Config\Config;
use ByJG\Config\Exception\ConfigException;
use ByJG\Config\Exception\ConfigNotFoundException;
use ByJG\Config\Exception\DependencyInjectionException;
use ByJG\Config\Exception\KeyNotFoundException;
use ByJG\RestServer\Exception\Error400Exception;
use ByJG\RestServer\HttpRequest;
use ByJG\Serializer\Serialize;
use ByJG\XmlUtil\Exception\FileException;
use ByJG\XmlUtil\Exception\XmlUtilException;
use ByJG\XmlUtil\XmlDocument;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

class OpenApiContext
{
    /**
     * Validates request against OpenAPI schema and returns parsed payload.
     *
     * @return array|XmlDocument Returns XmlDocument for XML content, array for JSON/form data
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
    public static function validateRequest(HttpRequest $request, bool $preserveNullValues = false, ?Schema $schema = null): array|XmlDocument
    {
        $schema = $schema ?? Config::get(Schema::class);

        $path = $request->getRequestPath();
        $method = $request->serverString('REQUEST_METHOD') ?? 'GET';
        $contentTypeHeader = $request->getHeader('Content-Type') ?? '';
        $contentType = is_string($contentTypeHeader) ? strtolower($contentTypeHeader) : '';

        if (str_contains($contentType, 'xml')) {
            $xmlDoc = new XmlDocument($request->payload());

            try {
                $schema->getPathDefinition($path, $method);
                $schema->getRequestParameters($path, $method);
            } catch (Exception $ex) {
                throw new Error400Exception(explode("\n", $ex->getMessage())[0]);
            }

            return $xmlDoc;
        }

        if (str_contains($contentType, 'multipart/')) {
            $requestBody = $request->body();
            if (is_array($requestBody)) {
                $files = $request->uploadedFiles()->getKeys();
                $requestBody = array_merge($requestBody, array_combine($files, $files));
            }
        } else {
            $requestBody = json_decode($request->payload(), true);
        }

        try {
            $schema->getPathDefinition($path, $method);
            $bodyRequestDef = $schema->getRequestParameters($path, $method);
            $bodyRequestDef->match($requestBody);
        } catch (Exception $ex) {
            throw new Error400Exception(explode("\n", $ex->getMessage())[0]);
        }

        $requestBody = empty($requestBody) ? [] : $requestBody;

        if (!is_array($requestBody)) {
            $requestBody = [];
        }

        if (!$preserveNullValues) {
            $requestBody = Serialize::from($requestBody)->withDoNotParseNullValues()->toArray();
        }

        return $requestBody;
    }
}