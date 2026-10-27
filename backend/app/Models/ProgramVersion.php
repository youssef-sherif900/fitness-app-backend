<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProgramVersion extends Model
{
    use HasUuids;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['metadata' => 'array', 'published_at' => 'datetime'];

    public function program()
    {
        return $this->belongsTo(Program::class);
    }
}