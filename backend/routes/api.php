<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BodyController;
use App\Http\Controllers\Api\FoodController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NutritionController;
use App\Http\Controllers\Api\ExerciseController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\ProgressionController;
use App\Http\Controllers\Api\WorkoutController;
use App\Http\Controllers\Api\WorkoutSetController;
use App\Http\Controllers\Api\ProgressController;
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



        // Exercises
        Route::get('exercises', [ExerciseController::class, 'index']);
        Route::post('exercises', [ExerciseController::class, 'store']);
        Route::get('exercises/{exerciseId}', [ExerciseController::class, 'show']);
        Route::patch('exercises/{exerciseId}', [ExerciseController::class, 'update']);
        Route::delete('exercises/{exerciseId}', [ExerciseController::class, 'destroy']);

        // Templates
        Route::get('training/templates', [ProgramController::class, 'templates']);
        Route::post('training/templates/{templateId}/copy', [ProgramController::class, 'copyTemplate']);

        // Programs (static 'current' before {programId})
        Route::get('training/programs/current', [ProgramController::class, 'current']);
        Route::get('training/programs', [ProgramController::class, 'index']);
        Route::post('training/programs', [ProgramController::class, 'store']);
        Route::get('training/programs/{programId}', [ProgramController::class, 'show']);
        Route::patch('training/programs/{programId}', [ProgramController::class, 'update']);
        Route::put('training/programs/{programId}/days', [ProgramController::class, 'updateDays']);
        Route::post('training/programs/{programId}/publish', [ProgramController::class, 'publish']);
        Route::post('training/programs/{programId}/archive', [ProgramController::class, 'archive']);
        Route::post('training/programs/{programId}/assignments', [ProgramController::class, 'assign']);
        Route::get('training/programs/{programId}/assignments', [ProgramController::class, 'assignments']);
        Route::patch('training/assignments/{assignmentId}', [ProgramController::class, 'updateAssignment']);

        // Progression
        Route::get('training/programs/{programId}/days/{dayId}/progression', [ProgressionController::class, 'show']);
        Route::post('training/programs/{programId}/days/{dayId}/progression/apply', [ProgressionController::class, 'apply']);
        Route::get('training/programs/{programId}/progression-history', [ProgressionController::class, 'history']);

        // Workouts (static exercise routes before {workoutId})
        Route::get('workouts/exercises/{exerciseId}/history', [WorkoutController::class, 'history']);
        Route::get('workouts/exercises/{exerciseId}/last-performance', [WorkoutController::class, 'lastPerformance']);
        Route::get('workouts/exercises/{exerciseId}/next-set-suggestion', [WorkoutController::class, 'nextSetSuggestion']);
        Route::get('workouts', [WorkoutController::class, 'index']);
        Route::post('workouts', [WorkoutController::class, 'store']);
        Route::get('workouts/{workoutId}', [WorkoutController::class, 'show']);
        Route::patch('workouts/{workoutId}', [WorkoutController::class, 'update']);
        Route::post('workouts/{workoutId}/exercises', [WorkoutController::class, 'addExercise']);
        Route::put('workouts/{workoutId}/exercises/order', [WorkoutController::class, 'reorder']);
        Route::post('workouts/{workoutId}/complete', [WorkoutController::class, 'complete']);
        Route::post('workouts/{workoutId}/cancel', [WorkoutController::class, 'cancel']);
        Route::post('workouts/{workoutId}/sets', [WorkoutSetController::class, 'store']);
        Route::patch('workouts/{workoutId}/sets/{setId}', [WorkoutSetController::class, 'update']);
        Route::delete('workouts/{workoutId}/sets/{setId}', [WorkoutSetController::class, 'destroy']);

        // Progress
        Route::get('progress/personal-records', [ProgressController::class, 'records']);
        Route::get('progress/exercises/{exerciseId}/personal-records', [ProgressController::class, 'exerciseRecords']);
        Route::get('progress/exercises/{exerciseId}/personal-records/history', [ProgressController::class, 'recordHistory']);
        Route::get('progress/weekly-volume', [ProgressController::class, 'weeklyVolume']);
        Route::get('progress/weekly-volume/history', [ProgressController::class, 'volumeHistory']);




    });
});
