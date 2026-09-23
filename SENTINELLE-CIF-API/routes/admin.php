<?php

use App\Http\Controllers\Api\AdminStructureController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminScreeningController;
use Illuminate\Support\Facades\Route;

Route::get('/caisses', [AdminStructureController::class, 'index'])->middleware('permission:org.register');
Route::post('/caisses', [AdminStructureController::class, 'store'])->middleware('permission:org.register');
Route::get('/caisses/{id}/access', [AdminStructureController::class, 'access'])->middleware('permission:org.register');
Route::post('/caisses/{id}/access-key', [AdminStructureController::class, 'provisionApiKey'])->middleware('permission:org.register');
Route::get('/caisses/{id}/csv-examples', [AdminStructureController::class, 'csvExamples'])->middleware('permission:org.register');
Route::get('/users', [AdminUserController::class, 'index'])->middleware('permission:user.manage');
Route::post('/users', [AdminUserController::class, 'store'])->middleware('permission:user.manage');
Route::patch('/users/{id}', [AdminUserController::class, 'update'])->middleware('permission:user.manage');
Route::get('/screening-lists', [AdminScreeningController::class, 'index'])->middleware('permission:list.import');
Route::post('/screening-lists/{id}/import', [AdminScreeningController::class, 'import'])->middleware(['permission:list.import', 'permission:list.publish']);
Route::get('/screening-lists/{id}/batches', [AdminScreeningController::class, 'batches'])->middleware('permission:list.import');
Route::post('/screening/run-batch', [AdminScreeningController::class, 'runBatch'])->middleware('permission:screening.run_batch');
