<?php

namespace ByJG\Gluo\Trait;

use ByJG\MicroOrm\Attributes\FieldAttribute;
use ByJG\MicroOrm\Trait\CreatedAt;
use OpenApi\Attributes as OA;

trait OaCreatedAt
{
    use CreatedAt;

    #[OA\Property(type: "string", format: "date-time", nullable: true)]
    #[FieldAttribute(fieldName: "created_at", syncWithDb: false)]
    protected string|null $createdAt = null;
}
