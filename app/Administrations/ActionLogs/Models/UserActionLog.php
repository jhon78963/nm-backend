<?php

namespace App\Administrations\ActionLogs\Models;

use App\Administrations\Users\Models\User;
use App\Directories\Teams\Models\Team;
use App\Administrations\Warehouses\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserActionLog extends Model
{
    public $timestamps = false;

    protected $table = 'user_action_logs';

    protected $fillable = [
        'creation_time',
        'user_id',
        'team_id',
        'warehouse_id',
        'action',
        'description',
        'metadata',
        'ip_address',
    ];

    protected $casts = [
        'creation_time' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
