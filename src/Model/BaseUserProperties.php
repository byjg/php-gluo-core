<?php

namespace ByJG\Gluo\Model;

use ByJG\Authenticate\Model\UserPropertiesModel;
use ByJG\MicroOrm\Attributes\FieldAttribute;
use ByJG\MicroOrm\Attributes\FieldUuidAttribute;
use ByJG\MicroOrm\Literal\Literal;

abstract class BaseUserProperties extends UserPropertiesModel
{
    #[FieldUuidAttribute]
    protected string|int|Literal|null $userid = null;

    #[FieldAttribute(primaryKey: true)]
    protected ?string $id = null;

    #[FieldAttribute]
    protected ?string $name = null;

    #[FieldAttribute]
    protected ?string $value = null;
}