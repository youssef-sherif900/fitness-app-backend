<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Section 9: reports the status of database, Redis, and storage. */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => fn () => DB::select('select 1') !== null,
            'redis' => fn () => (bool) Redis::connection()->ping(),
            'storage' => fn () => Storage::disk('s3')->put('health/ping', (string) time()) === true,
        ];

        $result = [];
        foreach ($checks as $name => $check) {
            try {
                $result[$name] = $check() ? 'ok' : 'down';
            } catch (Throwable) {
                $result[$name] = 'down';
            }
        }
        $ok = ! in_array('down', $result, true);

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $result], $ok ? 200 : 503);
    }
}
