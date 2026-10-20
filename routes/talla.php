<?php

use App\Http\Controllers\TallaController;
use Illuminate\Support\Facades\Route;

/*
 * سحب طلّة — مؤقّت. يُحذف بحذف هذا الملفّ وسطرِ استدعائه في web.php
 * والمتحكّم ومجلّد resources/views/talla.
 */
Route::prefix('talla')->group(function () {
    Route::get('/', [TallaController::class, 'enter'])->name('talla');
    Route::post('/name', [TallaController::class, 'name'])->middleware('throttle:20,1')->name('talla.name');
    Route::post('/answer', [TallaController::class, 'answer'])->middleware('throttle:20,1')->name('talla.answer');

    Route::middleware(['auth', 'role:super_admin'])->group(function () {
        Route::get('/draw', [TallaController::class, 'board'])->name('talla.board');
        Route::post('/draw/question', [TallaController::class, 'question'])->name('talla.question');
        Route::post('/draw/pick', [TallaController::class, 'draw'])->name('talla.pick');
        Route::post('/draw/reset', [TallaController::class, 'reset'])->name('talla.reset');
    });
});
