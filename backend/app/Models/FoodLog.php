<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class FoodLog extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    protected $casts = ['qty' => 'float', 'kcal' => 'float', 'protein_g' => 'float', 'carbs_g' => 'float', 'fat_g' => 'float', 'logged_at' => 'datetime', 'logged_on' => 'date:Y-m-d'];
}
