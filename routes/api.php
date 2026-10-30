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

// Tenant WhatsApp bot (Meta WhatsApp Cloud API). GET verifies the webhook once;
// POST carries messages, signed with the app secret.
Route::get('whatsapp/webhook', [\App\Http\Controllers\Api\WhatsAppWebhookController::class, 'verify'])->middleware('throttle:30,1')->name('api.whatsapp.verify');
Route::post('whatsapp/webhook', [\App\Http\Controllers\Api\WhatsAppWebhookController::class, 'receive'])->middleware('throttle:600,1')->name('api.whatsapp.receive');

// Yo! Custom USSD app call-outs (tenants paying rent by USSD; see deploy/YO_USSD.md).
// Signed by Yo!; each step of the menu has its own URL.
Route::post('ussd/yo/{step}', \App\Http\Controllers\Api\YoUssdController::class)
    ->whereIn('step', ['unit', 'units', 'confirm'])
    ->middleware('throttle:600,1')
    ->name('api.ussd.yo');
