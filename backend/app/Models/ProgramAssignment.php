<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProgramAssignment extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function version()
    {
        return $this->belongsTo(ProgramVersion::class, 'program_version_id');
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function trainer()
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }
}