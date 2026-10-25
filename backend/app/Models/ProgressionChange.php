<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProgressionChange extends Model
{
    use HasUuids;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['previous' => 'array', 'new' => 'array', 'created_at' => 'datetime'];
}