<?php

namespace App\Collection\Controllers;

use App\Collection\Services\CollectionService;
use App\Shared\Foundation\Controllers\Controller;
use App\Shared\Foundation\Services\SharedService;

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
