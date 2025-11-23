<?php

namespace App\Directory\Team\Services;

use App\Directory\Team\Models\Team;
use App\Shared\Foundation\Services\ModelService;

class TeamService extends ModelService
{
    public function __construct(Team $team)
    {
        parent::__construct($team);
    }
}
