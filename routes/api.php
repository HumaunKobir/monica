<?php

use App\Domains\Settings\ManageUsers\Api\Controllers\UserController;
use App\Domains\Vault\ManageVault\Api\Controllers\TagApiController;
use App\Domains\Vault\ManageVault\Api\Controllers\VaultController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the bootstrap/app.php file and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->name('api.')->group(function () {
    // users
    Route::get('user', [UserController::class, 'user']);
    Route::apiResource('users', UserController::class)->only(['index', 'show']);

    // vaults
    Route::apiResource('vaults', VaultController::class);

    // Tag endpoints
    Route::get('tags', [TagApiController::class, 'index']);
    Route::post('tags', [TagApiController::class, 'store']);
    Route::put('tags/{id}', [TagApiController::class, 'update']);
    Route::delete('tags/{id}', [TagApiController::class, 'destroy']);

    // Contact tag endpoints
    Route::post('contacts/{id}/tags', [TagApiController::class, 'attachTags']);
    Route::delete('contacts/{contactId}/tags/{tagId}', [TagApiController::class, 'detachTag']);

    // Override contacts index to support tag filtering
    Route::get('contacts', [TagApiController::class, 'getContactsWithTags']);
});
