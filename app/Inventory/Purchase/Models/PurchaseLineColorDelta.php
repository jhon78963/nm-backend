<?php

namespace App\Inventory\Purchase\Models;

use App\Inventory\Color\Models\Color;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseLineColorDelta extends Model
{
    protected $table = 'purchase_line_color_deltas';

    public $timestamps = false;

    protected $fillable = [
        'purchase_line_id',
        'color_id',
        'quantity',
    ];

    /**
     * @return BelongsTo<PurchaseLine, PurchaseLineColorDelta>
     */
    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class, 'purchase_line_id');
    }

    /**
     * @return BelongsTo<Color, PurchaseLineColorDelta>
     */
    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }
}
