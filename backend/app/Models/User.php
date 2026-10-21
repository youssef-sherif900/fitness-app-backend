<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['password', 'apple_id', 'google_id'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_client' => 'boolean',
            'is_trainer' => 'boolean',
            'is_admin' => 'boolean',
        ];
    }

    public function profile(): HasOne { return $this->hasOne(Profile::class); }

    public function goals(): HasMany { return $this->hasMany(Goal::class); }

    public function targets(): HasMany { return $this->hasMany(Target::class); }

    public function activeGoal(): ?Goal
    {
        return $this->goals()->where('is_active', true)->latest()->first();
    }

    public function currentTarget(): ?Target
    {
        return $this->targets()->where('effective_from', '<=', now()->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('created_at')->first();
    }
}
