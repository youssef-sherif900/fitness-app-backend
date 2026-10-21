<?php

use App\Jobs\PurgeExpiredData;
use App\Jobs\RunWeeklyCheckin;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Hourly: start the weekly check-in for users whose local time is Monday 06:xx (per-user timezone, section 7).
Artisan::command('checkins:dispatch', function () {
    User::query()->where('is_client', true)->whereHas('goals', fn ($q) => $q->where('is_active', true))
        ->each(function (User $u) {
            $local = now($u->timezone ?: 'UTC');
            if ($local->isMonday() && $local->hour === 6) {
                RunWeeklyCheckin::dispatch($u->id, $local->copy()->startOfWeek()->toDateString());
            }
        });
})->purpose('Dispatch weekly check-ins for users whose local time matches');

Schedule::command('checkins:dispatch')->hourly()->withoutOverlapping();
Schedule::job(new PurgeExpiredData)->dailyAt('03:00')->onOneServer();
