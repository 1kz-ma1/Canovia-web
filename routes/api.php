<?php

use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\McpAuthorizationProbeController;
use App\Http\Controllers\ProviderActivityIntakeController;
use Illuminate\Support\Facades\Route;

Route::post('/integrations/github/webhook', GitHubWebhookController::class)
    ->middleware('throttle:300,1')
    ->name('api.github.webhook');


Route::post('/execution/activities', ProviderActivityIntakeController::class)
    ->middleware('throttle:120,1')
    ->name('api.execution.activities.store');

// Deliberately no live tools/token verification yet. Before activating a real
// MCP handler, replace this deny-all probe with reviewed OAuth 2.1 policy.
Route::match(['GET', 'POST', 'DELETE'], '/mcp', McpAuthorizationProbeController::class)
    ->middleware('throttle:30,1')
    ->name('api.mcp.auth_probe');
