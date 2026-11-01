<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** LOG-2: a reusable meal or recipe. Items are nutrient snapshots so history stays stable. */
class SavedMeal extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['items' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
