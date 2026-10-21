<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Food extends Model
{
    use HasUuids;

    protected $table = 'foods';

    protected $guarded = [];

    protected $casts = ['verified' => 'boolean'];

    public function servings()
    {
        return $this->hasMany(FoodServing::class, 'food_id');
    }
}
