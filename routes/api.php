<?php

use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\McpReadOnlyResourceController;
use App\Http\Controllers\ProviderActivityIntakeController;
use Illuminate\Support\Facades\Route;

Route::post('/integrations/github/webhook', GitHubWebhookController::class)
    ->middleware('throttle:300,1')
    ->name('api.github.webhook');


Route::post('/execution/activities', ProviderActivityIntakeController::class)
    ->middleware('throttle:120,1')
    ->name('api.execution.activities.store');

// Real MCP tools are independently OFF by default. The controller preserves
// the existing deny-all probe until explicitly enabled after IdP/consent
// security review; every active request requires a fresh bearer introspection.
Route::match(['GET', 'POST', 'DELETE'], '/mcp', McpReadOnlyResourceController::class)
    ->middleware('throttle:30,1')
    ->name('api.mcp.auth_probe');
