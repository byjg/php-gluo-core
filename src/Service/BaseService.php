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
    public function update(mixed $id, array $payload): mixed
    {
        $model = $this->getOrFail($id);

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
     * @throws DatabaseException
     * @throws DbDriverNotConnected
     * @throws Error404Exception
     * @throws FileException
     * @throws InvalidArgumentException
     * @throws OrmInvalidFieldsException
     * @throws XmlUtilException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function getOrFail(array|string|int|LiteralInterface $id): mixed
    {
        $result = $this->baseRepository->get($id);
        if (empty($result)) {
            throw new Error404Exception('Id not found');
        }
        return $result;
    }

    /**
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

    public function delete(mixed $id): void
    {
        $this->baseRepository->delete($id);
    }
}