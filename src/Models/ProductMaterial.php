<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductMaterial extends Model
{
    protected $table = 'product_materials';

    protected $fillable = [
        'product_master_id',
        'material_id',
        'quantity',
        'notes',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductMaster::class, 'product_master_id');
    }
}
