<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::get('products', [ProductController::class, 'index']);
Route::get('products/{product}', [ProductController::class, 'show']);
Route::get('warehouses', [WarehouseController::class, 'index']);
Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:orders');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);

    Route::get('orders/{order}/payments', [PaymentController::class, 'index']);
    Route::post('orders/{order}/payments', [PaymentController::class, 'store']);
    Route::get('payments/{payment}', [PaymentController::class, 'show']);

    Route::middleware('role:admin,staff')->group(function () {
        Route::post('products/deactivate-stale', [ProductController::class, 'deactivateStale']);
        Route::apiResource('products', ProductController::class)->except(['index', 'show']);
        Route::apiResource('warehouses', WarehouseController::class)->except(['index', 'show']);
        Route::apiResource('inventories', InventoryController::class)->except(['destroy']);
        Route::post('orders/{order}/status', [OrderController::class, 'updateStatus']);
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::get('users/{user}', [UserController::class, 'show']);
    });
});
