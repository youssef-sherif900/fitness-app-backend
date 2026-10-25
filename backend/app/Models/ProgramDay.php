<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProgramDay extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $hidden = ['created_at', 'updated_at'];
    public function items()
    {
        return $this->hasMany(ProgramItem::class)->orderBy('position');
    }
}