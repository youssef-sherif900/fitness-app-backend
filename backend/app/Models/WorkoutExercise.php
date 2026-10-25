<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkoutExercise extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['planned' => 'array'];
    public function exercise()
    {
        return $this->belongsTo(Exercise::class);
    }
    public function sets()
    {
        return $this->hasMany(WorkoutSet::class)->orderBy('set_number');
    }
}