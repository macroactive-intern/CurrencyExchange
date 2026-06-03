<?php

use App\Http\Controllers\Api\ExchangeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/exchange', [ExchangeController::class, 'store']);
});
