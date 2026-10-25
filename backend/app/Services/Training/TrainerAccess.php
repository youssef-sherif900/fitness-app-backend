<?php
namespace App\Services;

use App\Models\Program;
use App\Models\User;

class TrainerAccess
{
    public function canEditPlan(User $actor, Program $program): bool
    {
        return false;
    }
    public function canAssignTo(User $actor, string $clientId): bool
    {
        return false;
    }
}