<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d', 'height_cm' => 'float', 'weight_kg' => 'float',
            'diet_preferences' => 'array', 'allergies' => 'array',
        ];
    }
}
