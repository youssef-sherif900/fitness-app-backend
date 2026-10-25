<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProgramItem extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $hidden = ['created_at', 'updated_at'];
    protected $appends = ['rep_range'];
    protected $casts = ['progression_rule' => 'array', 'target_rpe' => 'float', 'weight_kg' => 'float'];

    public function getRepRangeAttribute(): array
    {
        return ['min' => $this->rep_min, 'max' => $this->rep_max];
    }
}