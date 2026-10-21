<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

// CHAT-1 / CHAT-3: only the two participants of an ACTIVE trainer-client link may subscribe. Checked live (no caching),
// so revoking the link closes chat access immediately.
Broadcast::channel('conversation.{id}', function ($user, string $id) {
    return DB::table('conversations')
        ->join('trainer_clients', 'trainer_clients.id', '=', 'conversations.link_id')
        ->where('conversations.id', $id)
        ->where('trainer_clients.status', 'active')
        ->where(fn ($q) => $q->where('trainer_clients.trainer_id', $user->id)->orWhere('trainer_clients.client_id', $user->id))
        ->exists();
});
