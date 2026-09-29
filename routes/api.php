<?php

use App\Http\Controllers\GitHubWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/integrations/github/webhook', GitHubWebhookController::class)
    ->middleware('throttle:300,1')
    ->name('api.github.webhook');
