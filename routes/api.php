<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

use App\Http\Controllers\Api\AuthController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});

Route::get('/check-tables', function () {
    return [
        'users_table' => \Schema::hasTable('users'),
        'migrations_table' => \Schema::hasTable('migrations')
    ];
});
Route::get('/run-migration', function () {
    \Artisan::call('migrate', ['--force' => true]);
    return "Migration Done";
});
Route::get('/seed-roles', function () {
    \DB::table('roles')->insert([
        [
            'name' => 'user',
            'slug' => 'user'
        ],
        [
            'name' => 'provider',
            'slug' => 'provider'
        ]
    ]);
    return "Roles inserted";
});
