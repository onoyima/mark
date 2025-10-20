<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\BirthdayController;

Route::get('/send-specific-birthday', [BirthdayController::class, 'sendBirthdayEmailToSpecificUsers']);


Route::get('/', function () {
    return Redirect::to('/status');
});

Route::get('/status', function () {
    $list = Cache::get('api_status_list', []);
    return view('status', ['status_list' => $list]);
});

// Named login route for authentication middleware
Route::get('/login', function () {
    return response()->json(['message' => 'Please login via API'], 401);
})->name('login');

// Admin routes
Route::prefix('admin')->name('admin.')->middleware(['auth'])->group(function () {
    // Payment management routes
    Route::prefix('payments')->name('payments.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\PaymentController::class, 'index'])->name('index');
        Route::get('/{id}', [\App\Http\Controllers\Admin\PaymentController::class, 'show'])->name('show');
        Route::post('/{id}/verify', [\App\Http\Controllers\Admin\PaymentController::class, 'verify'])->name('verify');
        Route::post('/verify-all', [\App\Http\Controllers\Admin\PaymentController::class, 'verifyAll'])->name('verify-all');
    });
});



