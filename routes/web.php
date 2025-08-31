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



