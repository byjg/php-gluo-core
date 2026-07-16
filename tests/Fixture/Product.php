<?php

namespace ByJGTest\Gluo\Fixture;

use ByJG\Gluo\Trait\OaCreatedAt;
use ByJG\MicroOrm\Attributes\FieldAttribute;
use ByJG\MicroOrm\Attributes\TableAttribute;

#[TableAttribute("gluo_product")]
class Product
{
    use OaCreatedAt;

    #[FieldAttribute(primaryKey: true)]
    protected ?int $id = null;

    #[FieldAttribute]
    protected ?string $name = null;

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
