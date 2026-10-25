<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkoutSet extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $hidden = ['user_id', 'created_at', 'updated_at'];
    protected $casts = [
        'weight_kg' => 'float',
        'rpe' => 'float',
        'distance_meters' => 'float',
        'is_warmup' => 'boolean',
        'completed_at' => 'datetime',
    ];
    public function workoutExercise()
    {
        return $this->belongsTo(WorkoutExercise::class);
    }
}