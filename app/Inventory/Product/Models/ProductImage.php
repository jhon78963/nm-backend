<?php

namespace App\Inventory\Product\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    use HasFactory;

    // 1. Especificar el nombre exacto de la tabla (por defecto Laravel buscaría 'product_images')
    protected $table = 'product_image';

    // 2. Desactivar timestamps ya que no existen created_at ni updated_at en tu tabla
    public $timestamps = false;

    // 3. Desactivar el autoincremento y la llave primaria por defecto ya que usas llave compuesta
    public $incrementing = false;
    protected $primaryKey = null;

    protected $fillable = [
        'product_id',
        'path',
        'size',
        'name',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Relación inversa: Una imagen pertenece a un producto
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
