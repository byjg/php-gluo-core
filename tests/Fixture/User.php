<?php

namespace Tests\Fixture;

use ByJG\Gluo\Model\BaseUser;
use ByJG\MicroOrm\Attributes\TableAttribute;

#[TableAttribute(tableName: 'users')]
class User extends BaseUser
{
}
