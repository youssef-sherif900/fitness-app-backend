<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('trainer_clients', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('trainer_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUuid('client_id')->nullable()->constrained('users')->cascadeOnDelete(); // set when the invite is accepted
            $t->string('invite_code', 16)->unique();
            $t->string('status', 10)->default('pending');   // pending | active | paused | revoked
            $t->json('scopes')->nullable();                  // nutrition, workouts, body, photos, plan_editing
            $t->timestamps();
            $t->index(['trainer_id', 'status']);
            $t->index(['client_id', 'status']);
        });

        Schema::create('access_audit', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('actor_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUuid('client_id')->constrained('users')->cascadeOnDelete();
            $t->string('action', 32);
            $t->string('resource', 64);
            $t->timestamp('at')->useCurrent();
            $t->index(['client_id', 'at']);
        });

        Schema::create('conversations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('link_id')->unique()->constrained('trainer_clients')->cascadeOnDelete();
            $t->timestamps();
        });

        Schema::create('attachments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('storage_key');
            $t->string('mime', 64)->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->unsignedInteger('duration')->nullable();    // seconds
            $t->string('thumb_key')->nullable();
            $t->json('waveform')->nullable();
            $t->string('state', 12)->default('uploaded');   // uploaded | processing | ready | failed
            $t->timestamps();
            $t->index(['user_id', 'state']);
        });

        Schema::create('messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('sender_id')->constrained('users')->cascadeOnDelete();
            $t->uuid('client_uuid');
            $t->string('type', 12);                         // text | image | video | voice | shared_item
            $t->text('body')->nullable();
            $t->foreignUuid('attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $t->string('status', 10)->default('sent');      // sent | delivered | read
            $t->timestamp('sent_at');
            $t->timestamp('read_at')->nullable();
            $t->softDeletes();
            $t->unique(['sender_id', 'client_uuid']);
            $t->index(['conversation_id', 'sent_at']);
        });

        Schema::create('devices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('push_token')->nullable();
            $t->string('platform', 10);
            $t->json('reminders')->nullable();              // preferences + quiet hours
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('plan', 32);
            $t->string('status', 16);
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        Schema::create('sync_cursors', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('device_id', 64);
            $t->string('cursor', 64);
            $t->timestamps();
            $t->unique(['user_id', 'device_id']);
        });
    }

    public function down(): void
    {
        foreach (['sync_cursors', 'subscriptions', 'devices', 'messages', 'attachments', 'conversations', 'access_audit', 'trainer_clients'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
