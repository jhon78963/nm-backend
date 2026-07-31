<?php

namespace App\Directories\Teams\Services;

use App\Directories\Teams\Models\Team;
use App\Shared\Foundation\Services\ModelService;

class TeamService extends ModelService
{
    public function __construct(Team $team)
    {
        parent::__construct($team);
    }
}
