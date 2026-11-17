<?php

use App\Http\Controllers\Api\FutureSupportController;
use App\Http\Middleware\FutureSupportLabBoundary;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/future')->name('future.native.')->middleware(FutureSupportLabBoundary::class)->group(function () {
    Route::post('actors', [FutureSupportController::class, 'actors'])->name('actors');
    Route::delete('actors/current', [FutureSupportController::class, 'revoke'])->name('revoke');
    Route::get('features', [FutureSupportController::class, 'index'])->name('index');
    Route::post('features/{featureKey}/support', [FutureSupportController::class, 'support'])->name('support')->where('featureKey', '[a-z0-9][a-z0-9._-]{0,119}');
    Route::delete('features/{featureKey}/support', [FutureSupportController::class, 'unsupport'])->name('unsupport')->where('featureKey', '[a-z0-9][a-z0-9._-]{0,119}');
});
