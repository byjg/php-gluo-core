<?php

namespace ByJG\Gluo\Model;

use ByJG\Authenticate\MapperFunctions\PasswordBcryptMapper;
use ByJG\Authenticate\Model\UserModel;
use ByJG\MicroOrm\Attributes\FieldAttribute;
use ByJG\MicroOrm\Attributes\FieldUuidAttribute;
use ByJG\MicroOrm\Literal\Literal;
use OpenApi\Attributes as OA;

#[OA\Schema(required: ["email"], type: "object")]
abstract class BaseUser extends UserModel
{
    // Reset flow fields
    const string PROP_RESET_TOKEN_EXPIRE = 'resettokenexpire';
    const string PROP_RESET_TOKEN = 'resettoken';
    const string PROP_RESET_CODE = 'resetcode';
    const string PROP_RESET_ALLOWED = 'resetallowed';

    const string VALUE_YES = 'yes';
    const string VALUE_NO = 'no';

    const string ROLE_ADMIN = 'admin';
    const string ROLE_USER = 'user';

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

    // bcrypt; a SHA-1 hash of an older database still logs in and is replaced at the next login.
    // The column needs 60 characters: VARCHAR(255)
    #[OA\Property(type: "string", format: "string")]
    #[FieldAttribute(updateFunction: PasswordBcryptMapper::class)]
    protected ?string $password = null;

    #[OA\Property(type: "string", format: "string")]
    #[FieldAttribute]
    protected ?string $role = null;

    protected array $propertyList = [];
}