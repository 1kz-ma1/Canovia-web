<?php

use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\ProviderActivityIntakeController;
use Illuminate\Support\Facades\Route;

Route::post('/integrations/github/webhook', GitHubWebhookController::class)
    ->middleware('throttle:300,1')
    ->name('api.github.webhook');


Route::post('/execution/activities', ProviderActivityIntakeController::class)
    ->middleware('throttle:120,1')
    ->name('api.execution.activities.store');
