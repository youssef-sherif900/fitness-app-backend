<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Goal extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array { return ['is_active' => 'boolean', 'weekly_rate_kg' => 'float', 'target_weight_kg' => 'float']; }
}
