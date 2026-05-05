<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use WizcodePl\LunarPayu\Http\Controllers\PayuWebhookController;
use WizcodePl\LunarPayu\Http\Middleware\VerifyPayuSignature;

Route::post(
    config('lunar-payu.webhook_path', 'payu/notify'),
    PayuWebhookController::class,
)
    ->name('lunar-payu.notify')
    ->middleware(VerifyPayuSignature::class)
    ->withoutMiddleware([VerifyCsrfToken::class]);
