<?php

namespace ByJG\Gluo\Trait;

use ByJG\MicroOrm\Attributes\FieldAttribute;
use ByJG\MicroOrm\Trait\DeletedAt;
use OpenApi\Attributes as OA;

trait OaDeletedAt
{
    use DeletedAt;

    #[OA\Property(type: "string", format: "date-time", nullable: true)]
    #[FieldAttribute(fieldName: "deleted_at", syncWithDb: false)]
    protected string|null $deletedAt = null;
}
