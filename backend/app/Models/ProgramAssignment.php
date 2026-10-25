<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProgramAssignment extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['start_date' => 'date:Y-m-d'];
    public function program()
    {
        return $this->belongsTo(Program::class);
    }
}