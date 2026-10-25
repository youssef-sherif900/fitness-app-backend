<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Exercise extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $hidden = ['archived_at'];
    protected $appends = ['is_custom'];
    protected $casts = [
        'instructions' => 'array',
        'primary_muscles' => 'array',
        'secondary_muscles' => 'array',
        'equipment' => 'array',
        'media' => 'array',
    ];

    public function getIsCustomAttribute(): bool
    {
        return $this->owner_id !== null;
    }

    public function scopeVisibleTo($q, User $u)
    {
        return $q->where(fn($w) => $w->whereNull('owner_id')->orWhere('owner_id', $u->id));
    }
}