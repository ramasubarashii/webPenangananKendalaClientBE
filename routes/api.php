<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TicketController;
use App\Models\User;

// Public Auth Route
Route::post('/login', [AuthController::class, 'login']);

// Authenticated Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Tickets routes
    Route::get('/tickets', [TicketController::class, 'index']);
    Route::post('/tickets', [TicketController::class, 'store'])->middleware('role:service_desk');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
    
    // Assign Ticket (PM Only)
    Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])->middleware('role:project_manager');
    
    // Update Ticket Status
    Route::post('/tickets/{ticket}/status', [TicketController::class, 'updateStatus']);

    // Helper: List Programmers (For PM assign form)
    Route::get('/programmers', function () {
        return response()->json(User::where('role', 'programmer')->get(['id', 'name', 'email']));
    })->middleware('role:project_manager');
});
