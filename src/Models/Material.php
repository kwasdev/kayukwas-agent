<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $table = 'materials';

    protected $fillable = [
        'material_name',
        'material_type_id',
        'material_category_id',
        'material_unit_id',
        'grade',
        'wood_form',
        'stock',
        'description',
        'is_active',
    ];
}
