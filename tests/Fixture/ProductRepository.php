<?php

namespace ByJGTest\Gluo\Fixture;

use ByJG\AnyDataset\Db\DatabaseExecutor;
use ByJG\Gluo\Repository\BaseRepository;
use ByJG\MicroOrm\Repository;

class ProductRepository extends BaseRepository
{
    public function __construct(DatabaseExecutor $executor)
    {
        $this->repository = new Repository($executor, Product::class);
    }
}
