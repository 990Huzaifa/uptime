<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SiteLinkController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Broadcast::routes(['middleware' => ['auth:sanctum']]);


Route::post('signup', [AuthController::class, 'signup']);
Route::post('signin', [AuthController::class, 'signin']);
Route::post('social',[AuthController::class,'socialLoginSignup']);
Route::post('account-check',[AuthController::class,'accountCheck']);
Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('reset-password', [AuthController::class, 'resetPassword']);
Route::post('resend-code', [AuthController::class, 'resendCode']);

Route::get('/optimize-clear', function () {
    Artisan::call('optimize:clear');
    return 'Optimization cache cleared!';
});



Route::post('/webhook/apple', [WebhookController::class, 'handleApple']);
Route::post('/webhook/google', [WebhookController::class, 'handleGoogle']);

Route::middleware(['auth:sanctum'])->group(function () {

    Route::post('change-password', [AuthController::class, 'changePassword']);
    Route::get('/logout', [AuthController::class, 'logout']);

    Route::controller(SiteLinkController::class)->group(function () {
        Route::get('/site-link', 'index');
        Route::post('/site-link', 'store');
        Route::get('/site-link/{id}', 'show');
        Route::put('/site-link/{id}', 'update');
        Route::delete('/site-link/{id}', 'destroy');
        Route::put('/status/site-link/{id}', 'updateStatus');
        Route::get('/site-notify/{id}', 'notifyToggle');

        Route::get('enable-link/{id}', 'enableLink');
        
    });

    Route::controller(ProfileController::class)->group(function () {
        Route::get('/profile', 'profile');
        Route::post('/profile', 'updateProfile');
        Route::get('/check-plan', 'checkPlan');


    });

        
    Route::apiResource('notification', NotificationController::class)->only(['index']);
    Route::get('notification/mark-as-read/{id}', [NotificationController::class, 'markAsRead']);
    Route::delete('notification/{id}', [NotificationController::class, 'destroy']);
    Route::get('run-event', [NotificationController::class, 'runEvent']);

    Route::post('google/verify-payment', [PaymentController::class, 'verifyGoogle']);
    Route::post('apple/verify-payment', [PaymentController::class, 'verifyApple']);

        
});
