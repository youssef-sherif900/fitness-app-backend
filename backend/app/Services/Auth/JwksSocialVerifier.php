<?php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Verifies Apple / Google ID tokens against the providers' published signing keys (AUTH-2). */
class JwksSocialVerifier implements SocialTokenVerifier
{
    private const PROVIDERS = [
        'google' => ['jwks' => 'https://www.googleapis.com/oauth2/v3/certs', 'iss' => ['https://accounts.google.com', 'accounts.google.com']],
        'apple' => ['jwks' => 'https://appleid.apple.com/auth/keys', 'iss' => ['https://appleid.apple.com']],
    ];

    public function verify(string $provider, string $idToken): ?array
    {
        $cfg = self::PROVIDERS[$provider] ?? null;
        $aud = config("fitness.social.{$provider}_client_id");
        if (! $cfg || ! $aud) {
            return null;
        }

        try {
            $jwks = Cache::remember("jwks:{$provider}", 3600, fn () => Http::timeout(5)->get($cfg['jwks'])->throw()->json());
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($jwks));
        } catch (Throwable) {
            return null;
        }

        if (! in_array($claims['iss'] ?? '', $cfg['iss'], true) || ! in_array($aud, (array) ($claims['aud'] ?? []), true)) {
            return null;
        }

        return [
            'sub' => (string) $claims['sub'],
            'email' => $claims['email'] ?? null,
            'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'name' => $claims['name'] ?? null,
        ];
    }
}
