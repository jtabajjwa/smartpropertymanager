<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Marketing website (primoraproptech.com): published plans and prices.
// Server-to-server only; needs the shared X-Website-Key header.
Route::get('website/plans', \App\Http\Controllers\Api\WebsitePlansController::class)
    ->middleware(['throttle:60,1', \App\Http\Middleware\VerifyWebsiteKey::class])
    ->name('api.website.plans');
