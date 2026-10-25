<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PersonalRecord extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $hidden = ['user_id', 'created_at', 'updated_at'];
    protected $appends = ['unit'];
    protected $casts = ['value' => 'float', 'achieved_at' => 'datetime'];
    public function getUnitAttribute(): string
    {
        return $this->record_type === 'max_reps' ? 'reps' : 'kg';
    }
}