<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['is_template' => 'boolean', 'version' => 'integer', 'published_version' => 'integer'];

    public function days()
    {
        return $this->hasMany(ProgramDay::class)->orderBy('day_number');
    }
    public function assignments()
    {
        return $this->hasMany(ProgramAssignment::class);
    }

    public function scopeVisibleTo($q, User $u)
    {
        return $q->where('is_template', false)->where(function ($w) use ($u) {
            $w->where('owner_id', $u->id)->orWhereIn(
                'id',
                ProgramAssignment::select('program_id')->where('client_id', $u->id)->whereIn('status', ['active', 'paused'])
            );
        });
    }
}