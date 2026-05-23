<?php

namespace App\Finance\Sale\Models;

use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSeries extends Model
{
    protected $table = 'document_series';

    protected $fillable = [
        'warehouse_id',
        'document_type',
        'serie',
        'current_number',
    ];

    protected $casts = [
        'current_number' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
