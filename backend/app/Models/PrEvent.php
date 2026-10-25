<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PrEvent extends Model
{
    use HasUuids;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['previous_value' => 'float', 'new_value' => 'float', 'achieved_at' => 'datetime'];
}