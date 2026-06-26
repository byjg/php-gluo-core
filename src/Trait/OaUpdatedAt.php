<?php

namespace ByJG\Gluo\Trait;

use ByJG\MicroOrm\Attributes\FieldAttribute;
use ByJG\MicroOrm\Trait\UpdatedAt;
use OpenApi\Attributes as OA;

trait OaUpdatedAt
{
    use UpdatedAt;

    #[OA\Property(type: "string", format: "date-time", nullable: true)]
    #[FieldAttribute(fieldName: "updated_at", syncWithDb: false)]
    protected string|null $updatedAt = null;
}
