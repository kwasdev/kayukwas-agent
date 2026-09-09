<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinishingType extends Model
{
    protected $table = 'finishing_types';

    protected $fillable = [
        'name',
        'description',
    ];
}
