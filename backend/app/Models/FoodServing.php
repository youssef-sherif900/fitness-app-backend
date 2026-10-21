<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FoodServing extends Model
{
    use HasUuids;

    protected $guarded = [];

    public $timestamps = false;
    protected $casts = ['kcal' => 'float', 'protein_g' => 'float', 'carbs_g' => 'float', 'fat_g' => 'float', 'grams' => 'float', 'is_default' => 'boolean'];
}
