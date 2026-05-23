<?php

namespace App\Finance\Sale\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectronicDocumentLog extends Model
{
    protected $table = 'electronic_document_logs';

    protected $fillable = [
        'sale_id',
        'action',
        'request_payload',
        'response_payload',
        'sunat_code',
    ];

    protected $casts = [
        'request_payload'  => 'array',
        'response_payload' => 'array',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
