<?php

namespace ByJG\Gluo\Model;

use ByJG\Authenticate\Definition\PasswordDefinition;
use ByJG\Authenticate\MapperFunctions\PasswordSha1Mapper;
use ByJG\Authenticate\Model\UserModel;
use ByJG\Config\Config;
use ByJG\MicroOrm\Attributes\FieldAttribute;
use ByJG\MicroOrm\Attributes\FieldUuidAttribute;
use ByJG\MicroOrm\Literal\Literal;
use Exception;
use OpenApi\Attributes as OA;

#[OA\Schema(required: ["email"], type: "object")]
abstract class BaseUser extends UserModel
{
    // Reset flow fields
    const PROP_RESETTOKENEXPIRE = 'resettokenexpire';
    const PROP_RESETTOKEN = 'resettoken';
    const PROP_RESETCODE = 'resetcode';
    const PROP_RESETALLOWED = 'resetallowed';

    const VALUE_YES = 'yes';
    const VALUE_NO = 'no';

    const ROLE_ADMIN = 'admin';
    const ROLE_USER = 'user';

    #[OA\Property(type: "string", format: "string")]
    #[FieldUuidAttribute(primaryKey: true)]
    protected string|int|Literal|null $userid = null;

    #[OA\Property(type: "string", format: "string")]
    #[FieldAttribute]
    protected ?string $name = null;

    #[OA\Property(type: "string", format: "string")]
    #[FieldAttribute]
    protected ?string $email = null;

    #[OA\Property(type: "string", format: "string")]
    #[FieldAttribute]
    protected ?string $username = null;

    #[OA\Property(type: "string", format: "string")]
    #[FieldAttribute(updateFunction: PasswordSha1Mapper::class)]
    protected ?string $password = null;

    #[OA\Property(type: "string", format: "string")]
    #[FieldAttribute]
    protected ?string $role = null;

    protected array $propertyList = [];

    /**
     * @throws Exception
     */
    public function __construct(string $name = "", string $email = "", string $username = "", string $password = "", string $role = "")
    {
        parent::__construct($name, $email, $username, $password, $role);
        $this->withPasswordDefinition(Config::get(PasswordDefinition::class));
    }
}