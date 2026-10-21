<?php

return [
    // PROF-2: documented formula. Mifflin-St Jeor BMR x activity factor.
    'activity_factors' => [
        'sedentary' => 1.2,
        'light' => 1.375,
        'moderate' => 1.55,
        'active' => 1.725,
        'very_active' => 1.9,
    ],

    // 1 kg of body mass ~ 7700 kcal (common approximation, documented in the API docs).
    'kcal_per_kg' => 7700,

    // PROF-3: PLACEHOLDER values. The PRD requires review by a qualified nutrition professional
    // before launch; they are configurable on purpose.
    'guardrails' => [
        'min_daily_kcal' => (int) env('FITNESS_MIN_DAILY_KCAL', 1200),
        'max_weekly_loss_pct' => (float) env('FITNESS_MAX_WEEKLY_LOSS_PCT', 1.0), // % of body weight
    ],

    'macros' => [
        'protein_g_per_kg' => (float) env('FITNESS_PROTEIN_G_PER_KG', 1.8),
        'fat_pct' => (float) env('FITNESS_FAT_PCT', 0.25),
    ],

    // BODY-1: exponentially weighted moving average. 0.1 daily smoothing is a common default.
    'trend' => ['alpha' => 0.1],

    // NUT-1: adaptive adjustment tuning.
    'adaptive' => [
        'window_days' => 14,
        'min_weigh_ins' => 4,
        'min_adherence' => 0.6,   // share of days with food logged
        'damping' => 0.5,         // apply half of the computed correction
        'max_step_kcal' => 150,   // never move more than this per week
    ],

    'storage' => [
        // Endpoint embedded in signed URLs so a phone on the LAN can reach storage (set AWS_PUBLIC_ENDPOINT).
        'public_endpoint' => env('AWS_PUBLIC_ENDPOINT'),
        'upload_ttl_seconds' => 900,
        'read_ttl_seconds' => 300,
    ],

    'social' => [
        'google_client_id' => env('GOOGLE_CLIENT_ID'),
        'apple_client_id' => env('APPLE_CLIENT_ID'),
    ],

    'account_deletion_days' => (int) env('FITNESS_ACCOUNT_DELETION_DAYS', 30),

    'media' => [
        'voice_max_seconds' => (int) env('MEDIA_VOICE_MAX_SECONDS', 180),
        'video_max_seconds' => (int) env('MEDIA_VIDEO_MAX_SECONDS', 60),
        'video_max_mb' => (int) env('MEDIA_VIDEO_MAX_MB', 50),
    ],
];
