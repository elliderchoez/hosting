<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShowcaseController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ContainerController;
use App\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;

// 1. Public Vitrina Showcase routes
Route::get('/', [ShowcaseController::class, 'index'])->name('welcome');
Route::get('/student/{userId}/profile', [ShowcaseController::class, 'studentProfile'])->name('student.profile');
Route::post('/contact/{studentId}', [ShowcaseController::class, 'contactStudent'])->name('student.contact')->middleware('throttle:6,1');
Route::post('/showcase/projects/{project}/start', [ShowcaseController::class, 'startDemo'])->name('showcase.start');
Route::post('/showcase/projects/{project}/stop', [ShowcaseController::class, 'stopDemo'])->name('showcase.stop');

// 2. Rutas de autenticaion de estudiantes
Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [ProjectController::class, 'index'])->name('dashboard');

    // Projects CRUD & Actions
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::post('/projects/{project}/rebuild', [ProjectController::class, 'rebuild'])->name('projects.rebuild');
    Route::patch('/projects/{project}/instructions', [ProjectController::class, 'updateInstructions'])->name('projects.instructions');
    Route::patch('/projects/{project}/link-backend', [ProjectController::class, 'linkBackend'])->name('projects.link-backend');
    
    // Container Controls
    Route::post('/projects/{project}/start', [ContainerController::class, 'start'])->name('projects.start');
    Route::post('/projects/{project}/stop', [ContainerController::class, 'stop'])->name('projects.stop');
    Route::get('/projects/{project}/logs', [ContainerController::class, 'logs'])->name('projects.logs');

    // Profile updates
    Route::get('/profile', [ProfileController::class, 'showProfessional'])->name('profile.professional');
    Route::get('/profile/account', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile/account', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile/account', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/profile/details', [ProfileController::class, 'updateDetails'])->name('profile.details');
    Route::get('/profile/cv/generate', [ProfileController::class, 'generateCv'])->name('profile.cv.generate');

    // Student Message Inbox
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::patch('/messages/{message}/read', [MessageController::class, 'markAsRead'])->name('messages.read');
    Route::delete('/messages/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');
});

require __DIR__.'/auth.php';
