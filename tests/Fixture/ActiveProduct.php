<?php

namespace Tests\Fixture;

use ByJG\Gluo\Trait\ActiveRecord;
use ByJG\MicroOrm\Attributes\FieldAttribute;
use ByJG\MicroOrm\Attributes\TableAttribute;

#[TableAttribute("gluo_product")]
class ActiveProduct
{
    use ActiveRecord;

    #[FieldAttribute(primaryKey: true)]
    protected ?int $id = null;

    #[FieldAttribute]
    protected ?string $name = null;

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
}
