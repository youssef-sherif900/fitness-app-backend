<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Checkin extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['metrics' => 'array', 'proposed_changes' => 'array', 'week_start' => 'date:Y-m-d'];
}
