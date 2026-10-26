<?php

namespace ByJG\Gluo\Util;

use ByJG\ApiTools\Base\Schema;
use ByJG\ApiTools\Exception\DefinitionNotFoundException;
use ByJG\ApiTools\Exception\GenericApiException;
use ByJG\ApiTools\Exception\InvalidDefinitionException;
use ByJG\ApiTools\Exception\NotMatchedException;
use ByJG\Config\Config;
use ByJG\Config\Exception\ConfigException;
use ByJG\Config\Exception\DependencyInjectionException;
use ByJG\Config\Exception\KeyNotFoundException;
use ByJG\Config\Exception\RunTimeException;
use ByJG\MicroOrm\Interface\EntityValidatorInterface;
use ByJG\RestServer\Exception\Error400Exception;
use ByJG\Serializer\Serialize;
use OpenApi\Attributes as OA;
use OpenApi\Undefined;
use Override;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;

/**
 * Validates a model against its own OpenAPI schema every time it is saved, so the rules of
 * its OA\Property attributes (types, lengths, ranges, required fields) also hold for a save
 * that does not come from a request: a command, a queue consumer, a service.
 *
 * Declared in the model's table attribute:
 *
 *     #[TableAttribute("product", validator: SchemaValidator::class)]
 *
 * The model is serialized as the API returns it and matched against
 * `#/components/schemas/<Model>`. A property marked `readOnly` (the generated key) is not
 * required, and a property that is null is treated as not set. A mismatch throws the same
 * 400 as #[ValidateRequest].
 */
class SchemaValidator implements EntityValidatorInterface
{
    /**
     * @param Schema|null $schema The specification; by default the application's, from the container
     */
    public function __construct(protected ?Schema $schema = null)
    {
    }

    /**
     * @param mixed $instance
     * @param bool $isInsert
     * @throws Error400Exception
     * @throws ReflectionException
     * @throws DefinitionNotFoundException
     * @throws GenericApiException
     * @throws InvalidDefinitionException
     * @throws ConfigException
     * @throws DependencyInjectionException
     * @throws KeyNotFoundException
     * @throws RunTimeException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws InvalidArgumentException
     */
    #[Override]
    public function validate(mixed $instance, bool $isInsert): void
    {
        $schema = $this->schema ?? Config::get(Schema::class);

        $values = array_filter(Serialize::from($instance)->toArray(), fn($value) => $value !== null);

        try {
            $schema->matchDefinition('#/components/schemas/' . self::schemaName($instance::class), $values);
        } catch (NotMatchedException $ex) {
            throw new Error400Exception(explode("\n", $ex->getMessage())[0]);
        }
    }

    /**
     * The name of the model's schema under `components/schemas`: the `schema` argument of its
     * OA\Schema attribute, or else the short name of the class, as swagger-php names it.
     *
     * @param class-string $class
     * @return string
     * @throws ReflectionException
     */
    public static function schemaName(string $class): string
    {
        $reflection = new ReflectionClass($class);
        foreach ($reflection->getAttributes(OA\Schema::class) as $attribute) {
            $name = $attribute->newInstance()->schema;
            if (!Undefined::isDefault($name)) {
                return $name;
            }
        }
        return $reflection->getShortName();
    }
}
