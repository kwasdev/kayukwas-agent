<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductMaster extends Model
{
    protected $table = 'product_masters';

    protected $fillable = [
        'product_name',
        'product_code',
        'dimension_length',
        'dimension_width',
        'dimension_height',
        'unit',
        'stock',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'stock' => 'integer',
    ];

    public function materials(): HasMany
    {
        return $this->hasMany(ProductMaterial::class, 'product_master_id');
    }
}
