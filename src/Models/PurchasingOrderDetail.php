<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchasingOrderDetail extends Model
{
    protected $table = 'purchasing_order_details';

    protected $fillable = [
        'purchasing_order_id',
        'product_id',
        'packing_id',
        'finishing_type_id',
        'stuffing_id',
        'quantity',
        'cbm',
        'construction',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'cbm' => 'decimal:3',
    ];

    public function purchasingOrder(): BelongsTo
    {
        return $this->belongsTo(PurchasingOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductMaster::class, 'product_id');
    }

    public function packing(): BelongsTo
    {
        return $this->belongsTo(Packing::class);
    }

    public function finishingType(): BelongsTo
    {
        return $this->belongsTo(FinishingType::class);
    }

    public function stuffing(): BelongsTo
    {
        return $this->belongsTo(Stuffing::class);
    }
}
