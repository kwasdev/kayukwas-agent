<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    protected $table = 'work_orders';

    protected $fillable = [
        'purchasing_order_id',
        'work_order_number',
        'issued_date',
        'status',
        'project_name',
        'notes',
        'type',
    ];

    public function purchasingOrder(): BelongsTo
    {
        return $this->belongsTo(PurchasingOrder::class);
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(WorkOrderConfirmation::class);
    }
}
