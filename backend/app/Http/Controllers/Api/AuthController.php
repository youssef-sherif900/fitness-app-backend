<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\SocialTokenVerifier;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const ACCESS_MINUTES = 60;
    private const REFRESH_DAYS = 60;

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email:rfc|max:255|unique:users,email',
            'password' => 'required|string|min:8|max:128',
            'name' => 'nullable|string|max:120',
            'device_id' => 'required|string|max:64',
            'is_trainer' => 'sometimes|boolean',
        ]);

        $user = User::create([
            'email' => strtolower($data['email']),
            'password' => $data['password'],
            'name' => $data['name'] ?? null,
            'is_trainer' => $data['is_trainer'] ?? false, // a trainer may also use the app as a client
        ]);
        event(new Registered($user)); // sends the verification email (AUTH-3)

        return response()->json(['data' => $this->session($user, $data['device_id'])], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_id' => 'required|string|max:64',
        ]);

        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
        }

        return response()->json(['data' => $this->session($user, $data['device_id'])]);
    }

    /** AUTH-2: Sign in with Apple / Google. The app sends the provider's ID token. */
    public function social(Request $request, SocialTokenVerifier $verifier): JsonResponse
    {
        $data = $request->validate([
            'provider' => 'required|in:apple,google',
            'id_token' => 'required|string',
            'device_id' => 'required|string|max:64',
            'name' => 'nullable|string|max:120',   // Apple only sends the name on first sign-in, via the client
        ]);

        $claims = $verifier->verify($data['provider'], $data['id_token']);
        if (! $claims) {
            throw ValidationException::withMessages(['id_token' => ['The sign-in token is invalid.']]);
        }

        $column = $data['provider'].'_id';
        $user = User::where($column, $claims['sub'])->first();

        if (! $user && $claims['email'] && $claims['email_verified']) {
            $user = User::where('email', strtolower($claims['email']))->first(); // link to the existing verified-email account
        }
        if (! $user) {
            if (! $claims['email']) {
                throw ValidationException::withMessages(['id_token' => ['The provider did not share an email address.']]);
            }
            $user = User::create([
                'email' => strtolower($claims['email']),
                'name' => $data['name'] ?? $claims['name'],
                $column => $claims['sub'],
                'email_verified_at' => $claims['email_verified'] ? now() : null,
            ]);
        } elseif (! $user->{$column}) {
            $user->forceFill([$column => $claims['sub']])->save();
        }

        return response()->json(['data' => $this->session($user, $data['device_id'])]);
    }

    /** Exchange a refresh token for a new pair (rotation: the old pair for this device is revoked). */
    public function refresh(Request $request): JsonResponse
    {
        $deviceId = $request->user()->currentAccessToken()->name;

        return response()->json(['data' => $this->session($request->user(), $deviceId)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->tokens()->where('name', $request->user()->currentAccessToken()->name)->delete();

        return response()->json(null, 204);
    }

    /** Per-device sessions (AUTH-1: per-device revoke). */
    public function sessions(Request $request): JsonResponse
    {
        $rows = $request->user()->tokens()->where('abilities', 'like', '%"access"%')
            ->get(['name', 'last_used_at', 'created_at'])
            ->map(fn ($t) => ['device_id' => $t->name, 'last_used_at' => $t->last_used_at, 'created_at' => $t->created_at]);

        return response()->json(['data' => $rows]);
    }

    public function revokeSession(Request $request, string $deviceId): JsonResponse
    {
        $request->user()->tokens()->where('name', $deviceId)->delete();

        return response()->json(null, 204);
    }

    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['data' => ['verified' => true]]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return response()->json(null, 202);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));

        // Always the same answer so the endpoint cannot be used to discover which emails are registered.
        return response()->json(['data' => ['message' => 'If the email exists, a reset link has been sent.']], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|max:128',
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete(); // sign out everywhere after a reset
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['data' => ['message' => 'Password updated.']]);
    }

    private function session(User $user, string $deviceId): array
    {
        $user->tokens()->where('name', $deviceId)->delete();

        $access = $user->createToken($deviceId, ['access'], now()->addMinutes(self::ACCESS_MINUTES));
        $refresh = $user->createToken($deviceId, ['refresh'], now()->addDays(self::REFRESH_DAYS));

        return [
            'user' => $user->only(['id', 'email', 'name', 'is_client', 'is_trainer', 'email_verified_at']),
            'access_token' => $access->plainTextToken,
            'access_expires_at' => $access->accessToken->expires_at,
            'refresh_token' => $refresh->plainTextToken,
            'refresh_expires_at' => $refresh->accessToken->expires_at,
            'device_id' => $deviceId,
        ];
    }
}
