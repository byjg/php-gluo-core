<?php

namespace ByJG\Gluo\Trait;

use ByJG\MicroOrm\Literal\LiteralInterface;
use ByJG\MicroOrm\Trait\ActiveRecord as MicroOrmActiveRecord;
use ByJG\RestServer\Exception\Error404Exception;
use ReflectionClass;

/**
 * MicroOrm's ActiveRecord, plus getOrFail(): the lookup a controller makes before it shows,
 * changes or deletes a record, answering 404 when there is none. It is the ActiveRecord
 * counterpart of BaseService::getOrFail(), and Gluo's version of Laravel's findOrFail().
 */
trait ActiveRecord
{
    use MicroOrmActiveRecord;

    /**
     * The record with this primary key.
     *
     * @param array|string|int|LiteralInterface $pk The key; an array for a composite key
     * @param string|null $message The message of the 404; by default "<Model> not found"
     * @throws Error404Exception When there is no record with this key
     */
    public static function getOrFail(array|string|int|LiteralInterface $pk, ?string $message = null): static
    {
        $model = static::get($pk);
        if (is_null($model)) {
            throw new Error404Exception($message ?? (new ReflectionClass(static::class))->getShortName() . " not found");
        }
        return $model;
    }
}
