<?php

use App\Http\Controllers\ThreadsToolsController;
use Illuminate\Support\Facades\Route;

Route::post('/orders/paid',[ThreadsToolsController::class,'paidWebhook'])->middleware('throttle:30,1');
