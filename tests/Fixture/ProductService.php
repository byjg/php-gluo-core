<?php

namespace ByJGTest\Gluo\Fixture;

use ByJG\Gluo\Service\BaseService;

class ProductService extends BaseService
{
    public function __construct(ProductRepository $repository)
    {
        parent::__construct($repository);
    }
}
