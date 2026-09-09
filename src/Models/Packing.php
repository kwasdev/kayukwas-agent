<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Packing extends Model
{
    protected $table = 'packings';

    protected $fillable = [
        'packing_name',
        'description',
    ];
}
