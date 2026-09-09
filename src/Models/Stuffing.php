<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stuffing extends Model
{
    protected $table = 'stuffings';

    protected $fillable = [
        'stuffing_name',
        'description',
    ];
}
