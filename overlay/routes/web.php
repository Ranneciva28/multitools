<?php

use App\Http\Controllers\ThreadsToolsController as C;
use Illuminate\Support\Facades\Route;

Route::get('/login',[C::class,'loginForm'])->name('login');
Route::post('/login',[C::class,'login'])->middleware('throttle:5,1');
Route::post('/logout',[C::class,'logout'])->middleware('auth');
Route::middleware(['auth','throttle:60,1'])->group(function () {
    Route::get('/',[C::class,'index']);
    Route::post('/accounts',[C::class,'account']);
    Route::post('/accounts/{id}/login',[C::class,'retryAccount']);
    Route::post('/accounts/{id}/check',[C::class,'checkAccount']);
    Route::post('/campaigns',[C::class,'campaign']);
    Route::post('/campaigns/{id}/duplicate',[C::class,'duplicate']);
    Route::post('/posts/{id}',[C::class,'updatePost']);
    Route::post('/posts/{id}/approve',[C::class,'approvePost']);
    Route::post('/posts/{id}/queue',[C::class,'queuePost']);
    Route::post('/media',[C::class,'media']);
    Route::get('/media/{id}',[C::class,'mediaPreview']);
    Route::post('/telegram/groups',[C::class,'group']);
    Route::post('/telegram/templates',[C::class,'template']);
    Route::post('/telegram/feed/{id}/approve',[C::class,'approveFeed']);
    Route::post('/telegram/feed/{id}/send',[C::class,'sendFeed']);
    Route::post('/telegram/templates/{id}/send',[C::class,'sendTemplate']);
});
