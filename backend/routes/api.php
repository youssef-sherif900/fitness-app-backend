<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BodyController;
use App\Http\Controllers\Api\FoodController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NutritionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class);

    // --- Public auth endpoints (stricter rate limit) ---
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('social', [AuthController::class, 'social']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
        Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
            ->middleware('signed')->name('verification.verify');
        // The refresh token can only be used here.
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware(['auth:sanctum', 'ability:refresh']);
    });

    // --- Authenticated endpoints (access token only) ---
    Route::middleware(['auth:sanctum', 'ability:access', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/email/resend', [AuthController::class, 'resendVerification']);
        Route::get('auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('auth/sessions/{deviceId}', [AuthController::class, 'revokeSession']);

        Route::get('me', [MeController::class, 'show']);
        Route::put('me/profile', [MeController::class, 'updateProfile']);
        Route::get('me/export', [MeController::class, 'export']);
        Route::delete('me', [MeController::class, 'destroy']);

        Route::get('nutrition/targets/current', [NutritionController::class, 'current']);
        Route::get('nutrition/targets', [NutritionController::class, 'history']);
        Route::post('nutrition/targets/recalculate', [NutritionController::class, 'recalculate']);

        Route::get('foods', [FoodController::class, 'index']);
        Route::get('foods/barcode/{code}', [FoodController::class, 'barcode']);
        Route::post('foods', [FoodController::class, 'store']);
        Route::get('foods/{food}', [FoodController::class, 'show']);
        Route::post('foods/{food}/report', [FoodController::class, 'report']);

        Route::get('logs', [LogController::class, 'index']);
        Route::get('logs/summary', [LogController::class, 'summary']);
        Route::post('logs', [LogController::class, 'store']);
        Route::post('logs/copy', [LogController::class, 'copy']);
        Route::patch('logs/{log}', [LogController::class, 'update']);
        Route::delete('logs/{log}', [LogController::class, 'destroy']);

        Route::get('body/weights', [BodyController::class, 'weights']);
        Route::post('body/weights', [BodyController::class, 'storeWeight']);
        Route::delete('body/weights/{weight}', [BodyController::class, 'destroyWeight']);

        Route::post('attachments', [AttachmentController::class, 'store'])->middleware('throttle:uploads');
    });
});
