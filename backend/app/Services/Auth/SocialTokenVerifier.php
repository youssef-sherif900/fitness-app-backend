<?php

namespace App\Services\Auth;

interface SocialTokenVerifier
{
    /** @return array{sub:string,email:?string,email_verified:bool,name:?string}|null  null when the token is invalid */
    public function verify(string $provider, string $idToken): ?array;
}
