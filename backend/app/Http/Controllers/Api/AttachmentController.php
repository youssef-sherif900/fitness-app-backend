<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Services\Storage\UploadSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AttachmentController extends Controller
{
    private const KINDS = [
        'image' => ['mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'max_mb' => 10],
        'voice' => ['mimes' => ['audio/mp4', 'audio/m4a', 'audio/x-m4a', 'audio/aac', 'audio/mpeg', 'audio/webm', 'audio/ogg'], 'max_mb' => 20],
        'video' => ['mimes' => ['video/mp4', 'video/quicktime'], 'max_mb' => null], // from config
    ];

    /**
     * MEDIA-1 (first half): reserve an attachment and hand the app a signed PUT URL.
     * The FFmpeg validation/processing step (second half) is Phase 4 and will move state pending -> processing -> ready/failed.
     */
    public function store(Request $request, UploadSigner $signer): JsonResponse
    {
        $d = $request->validate([
            'kind' => 'required|in:image,voice,video',
            'mime' => 'required|string|max:64',
            'size' => 'required|integer|min:1',
        ]);
        $rule = self::KINDS[$d['kind']];
        $maxBytes = ($rule['max_mb'] ?? config('fitness.media.video_max_mb')) * 1024 * 1024;

        abort_unless(in_array($d['mime'], $rule['mimes'], true), 422, 'Unsupported file type for this attachment.');
        abort_if($d['size'] > $maxBytes, 422, 'File is too large.');

        $user = $request->user();
        $idem = $request->header('Idempotency-Key');
        if ($idem && ($id = Cache::get("idem:att:{$user->id}:{$idem}")) && ($att = Attachment::where('user_id', $user->id)->find($id))) {
            return response()->json(['data' => $att, 'upload' => $signer->put($att->storage_key, $att->mime)], 200);
        }

        $id = (string) Str::uuid();
        $att = Attachment::create([
            'id' => $id, 'user_id' => $user->id, 'mime' => $d['mime'], 'size' => $d['size'], 'state' => 'pending',
            'storage_key' => "users/{$user->id}/attachments/{$id}",   // per-user prefix: account deletion removes it wholesale
        ]);
        if ($idem) {
            Cache::put("idem:att:{$user->id}:{$idem}", $id, 86400);
        }

        return response()->json(['data' => $att, 'upload' => $signer->put($att->storage_key, $d['mime'])], 201);
    }
}
