<?php

namespace Tests\Fixture;

use ByJG\Gluo\Trait\OaCreatedAt;
use ByJG\Gluo\Util\SchemaValidator;
use ByJG\MicroOrm\Attributes\FieldAttribute;
use ByJG\MicroOrm\Attributes\TableAttribute;
use OpenApi\Attributes as OA;

/** A model as codegen writes it, validated against its own schema on every save. */
#[OA\Schema(required: ["id", "name", "price"], type: "object", xml: new OA\Xml(name: "ValidatedProduct"))]
#[TableAttribute("gluo_product", validator: SchemaValidator::class)]
class ValidatedProduct
{
    use OaCreatedAt;

    #[OA\Property(type: "integer", format: "int32", readOnly: true)]
    #[FieldAttribute(primaryKey: true)]
    protected ?int $id = null;

    #[OA\Property(type: "string", format: "string", maxLength: 120, minLength: 1)]
    #[FieldAttribute]
    protected ?string $name = null;

    #[OA\Property(type: "number", format: "double", maximum: 99999999.99, minimum: 0)]
    #[FieldAttribute]
    protected ?float $price = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(?float $price): static
    {
        $this->price = $price;
        return $this;
    }
}
