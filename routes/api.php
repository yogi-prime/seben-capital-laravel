<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ChatbotController;
/*
| Public Blog APIs (no middleware)
*/
Route::get('/categories', [CategoryController::class, 'index']);
Route::post('/categories', [CategoryController::class, 'store']); // optional quick create

Route::get('/tags', [TagController::class, 'index']);
Route::post('/tags', [TagController::class, 'store']); // optional quick create

// Posts
Route::get('/posts', [PostController::class, 'index']);              // list with filters
Route::get('/posts/{slug}', [PostController::class, 'showBySlug']);  // detail
Route::post('/posts', [PostController::class, 'store']);             // create (multipart)
// *** Admin-friendly helpers (no auth for now) ***
Route::get('/posts/by-id/{id}', [PostController::class, 'show']);  // fetch by numeric id (for edit screen)
Route::put('/posts/{id}', [PostController::class, 'update']);      // update (multipart, same payload shape as store)
Route::delete('/posts/{id}', [PostController::class, 'destroy']);  // delete
Route::get('/posts/{slug}/related', [PostController::class, 'related']);
Route::get('/chatbot/flow', [ChatbotController::class, 'flow']);
Route::post('/chatbot/leads', [ChatbotController::class, 'saveLead']);

Route::get('/chatbot/leads', [ChatbotController::class, 'leads']);
Route::get('/chatbot/leads/{lead}', [ChatbotController::class, 'showLead']);