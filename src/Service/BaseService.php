<?php

namespace ByJG\Gluo\Service;

use ByJG\AnyDataset\Core\Exception\DatabaseException;
use ByJG\AnyDataset\Db\Exception\DbDriverNotConnected;
use ByJG\Gluo\Repository\BaseRepository;
use ByJG\MicroOrm\Exception\InvalidArgumentException;
use ByJG\MicroOrm\Exception\OrmBeforeInvalidException;
use ByJG\MicroOrm\Exception\OrmInvalidFieldsException;
use ByJG\MicroOrm\Exception\RepositoryReadOnlyException;
use ByJG\MicroOrm\Exception\UpdateConstraintException;
use ByJG\MicroOrm\Literal\LiteralInterface;
use ByJG\RestServer\Exception\Error404Exception;
use ByJG\RestServer\Exception\Error422Exception;
use ByJG\Serializer\ObjectCopy;
use ByJG\XmlUtil\Exception\FileException;
use ByJG\XmlUtil\Exception\XmlUtilException;
use ReflectionClass;

abstract class BaseService
{
    protected BaseRepository $baseRepository;

    public function __construct(BaseRepository $baseRepository)
    {
        $this->baseRepository = $baseRepository;
    }

    /**
     * @throws DatabaseException
     * @throws DbDriverNotConnected
     * @throws Error422Exception
     * @throws FileException
     * @throws InvalidArgumentException
     * @throws OrmBeforeInvalidException
     * @throws OrmInvalidFieldsException
     * @throws RepositoryReadOnlyException
     * @throws UpdateConstraintException
     * @throws XmlUtilException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function create(array $payload): mixed
    {
        $primaryKey = $this->baseRepository->getMapper()->getPrimaryKey();

        foreach ($primaryKey as $pkField) {
            if (!empty($payload[$pkField])) {
                throw new Error422Exception("Create should not include primary key field: {$pkField}");
            }
        }

        $model = $this->baseRepository->getMapper()->getEntity($payload);
        $this->baseRepository->save($model);
        return $model;
    }

    /**
     * Replaces the writable fields of the record $id with the payload, and saves it.
     *
     * @param mixed $id The key of the record, as in getOrFail()
     * @param array $payload The new values, by property name
     * @param string|null $message The message of the 404 when there is no such record, as in getOrFail()
     * @throws DatabaseException
     * @throws DbDriverNotConnected
     * @throws Error404Exception
     * @throws FileException
     * @throws InvalidArgumentException
     * @throws OrmBeforeInvalidException
     * @throws OrmInvalidFieldsException
     * @throws RepositoryReadOnlyException
     * @throws UpdateConstraintException
     * @throws XmlUtilException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function update(mixed $id, array $payload, ?string $message = null): mixed
    {
        $model = $this->getOrFail($id, $message);

        // The record is the one $id names: a key in the payload cannot point the update elsewhere
        foreach ($this->baseRepository->getMapper()->getPrimaryKey() as $pkField) {
            unset($payload[$pkField]);
        }
        ObjectCopy::copy($payload, $model);
        $this->baseRepository->save($model);
        return $model;
    }

    /**
     * @throws DatabaseException
     * @throws DbDriverNotConnected
     * @throws FileException
     * @throws InvalidArgumentException
     * @throws OrmInvalidFieldsException
     * @throws XmlUtilException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function get(array|string|int|LiteralInterface $id): mixed
    {
        return $this->baseRepository->get($id);
    }

    /**
     * The record with this key.
     *
     * @param array|string|int|LiteralInterface $id The key; an array for a composite key
     * @param string|null $message The message of the 404; by default "<Model> not found"
     * @throws DatabaseException
     * @throws DbDriverNotConnected
     * @throws Error404Exception
     * @throws FileException
     * @throws InvalidArgumentException
     * @throws OrmInvalidFieldsException
     * @throws XmlUtilException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function getOrFail(array|string|int|LiteralInterface $id, ?string $message = null): mixed
    {
        $result = $this->baseRepository->get($id);
        if (empty($result)) {
            throw new Error404Exception($message ?? $this->notFoundMessage());
        }
        return $result;
    }

    /**
     * @param int|null $page
     * @param int|null $size
     * @param string|array|null $orderBy
     * @return array
     * @throws DatabaseException
     * @throws DbDriverNotConnected
     * @throws FileException
     * @throws InvalidArgumentException
     * @throws XmlUtilException
     * @throws \ByJG\Serializer\Exception\InvalidArgumentException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function list(?int $page = 0, ?int $size = 20, string|array|null $orderBy = null): array
    {
        return $this->baseRepository->list($page ?? 0, $size ?? 20, $orderBy);
    }

    public function save(mixed $model): void
    {
        $this->baseRepository->save($model);
    }

    /**
     * Deletes the record $id (a soft delete when the model uses OaDeletedAt).
     *
     * @param mixed $id The key of the record, as in getOrFail()
     * @param string|null $message The message of the 404 when there is no such record
     * @throws Error404Exception When there is no record with this key
     */
    public function delete(mixed $id, ?string $message = null): void
    {
        // A missing record is a 404, as in getOrFail() and update(), not a silent success
        $this->getOrFail($id, $message);
        $this->baseRepository->delete($id);
    }

    /**
     * The message of the 404 when no message is given: "<Model> not found", as the
     * ActiveRecord trait's getOrFail() says it.
     */
    protected function notFoundMessage(): string
    {
        /** @var class-string $class the model the mapper was built from */
        $class = $this->baseRepository->getMapper()->getEntityClass();
        return (new ReflectionClass($class))->getShortName() . ' not found';
    }
}
