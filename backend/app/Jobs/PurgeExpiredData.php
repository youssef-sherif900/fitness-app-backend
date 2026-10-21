<?php

namespace App\Jobs;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/** AUTH-4 / PRIV-1: permanently delete accounts past the retention window (rows via FK cascade + storage prefix) and orphaned uploads. */
class PurgeExpiredData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $disk = Storage::disk('s3');

        User::onlyTrashed()->where('deleted_at', '<', now()->subDays(config('fitness.account_deletion_days')))
            ->each(function (User $u) use ($disk) {
                $disk->deleteDirectory("users/{$u->id}"); // photos, voice notes, videos, thumbnails
                $u->forceDelete();                        // FK cascades remove every row owned by the user
            });

        Attachment::where('state', 'pending')->where('created_at', '<', now()->subDay())
            ->each(function (Attachment $a) use ($disk) {
                $disk->delete($a->storage_key);
                $a->delete();
            });
    }
}
