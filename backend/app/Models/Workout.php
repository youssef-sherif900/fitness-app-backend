<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Workout extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    public function exercises()
    {
        return $this->hasMany(WorkoutExercise::class)->orderBy('position');
    }
    public function sets()
    {
        return $this->hasMany(WorkoutSet::class);
    }
}