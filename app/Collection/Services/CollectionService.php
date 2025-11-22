<?php

namespace App\Collection\Services;

use App\Collection\Models\Collection;
use App\Shared\Foundation\Services\ModelService;

class CollectionService extends ModelService
{
    public function __construct(Collection $collection)
    {
        parent::__construct($collection);
    }
}
