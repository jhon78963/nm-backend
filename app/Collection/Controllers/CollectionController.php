<?php

namespace App\Collection\Controllers;

use App\Collection\Services\CollectionService;
use App\Shared\Controllers\Controller;
use App\Shared\Services\SharedService;

class CollectionController extends Controller
{
    protected CollectionService $collectionService;
    protected SharedService $sharedService;

    public function __construct(CollectionService $collectionService, SharedService $sharedService)
    {
        $this->collectionService = $collectionService;
        $this->sharedService = $sharedService;
    }
}
