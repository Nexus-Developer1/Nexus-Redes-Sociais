<?php

use App\Http\Controllers\CalendarioController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\PostDecisionController;
use App\Http\Controllers\PostEditorController;
use App\Http\Controllers\PostRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [LoginController::class, 'show'])->name('login');
    Route::post('/entrar', [LoginController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::get('/', [PainelController::class, 'index'])->name('painel');
    Route::post('/sair', [LoginController::class, 'logout'])->name('logout');

    Route::post('/posts/{post}/aprovar', [PostDecisionController::class, 'approve'])->name('posts.approve');
    Route::post('/posts/{post}/rejeitar', [PostDecisionController::class, 'reject'])->name('posts.reject');
    Route::post('/posts/{post}/alterar', [PostDecisionController::class, 'requestChange'])->name('posts.change');
    Route::post('/posts/{post}/desfazer', [PostDecisionController::class, 'undo'])->name('posts.undo');
    Route::post('/posts/{post}/trocar-ideia', [PostDecisionController::class, 'swapIdea'])->name('posts.swap');
    Route::post('/posts/{post}/descartar', [PostDecisionController::class, 'discard'])->name('posts.discard');

    Route::get('/calendario', [CalendarioController::class, 'index'])->name('calendario');

    // Criar e editar posts no painel (modelos + legenda + data).
    Route::get('/posts/novo', [PostEditorController::class, 'create'])->name('posts.create');
    Route::post('/posts', [PostEditorController::class, 'store'])->name('posts.store');
    Route::get('/posts/{post}/editar', [PostEditorController::class, 'edit'])->name('posts.edit');
    Route::post('/posts/{post}/editar', [PostEditorController::class, 'update'])->name('posts.update');
    Route::post('/posts/{post}/modelo', [PostEditorController::class, 'useTemplate'])->name('posts.template');
    Route::post('/posts/{post}/imagens', [PostEditorController::class, 'upload'])->name('posts.upload');
    Route::post('/posts/{post}/enviar', [PostEditorController::class, 'submit'])->name('posts.submit');

    Route::post('/pedidos', [PostRequestController::class, 'store'])->name('requests.store');
    Route::post('/pedidos/{pedido}/repetir', [PostRequestController::class, 'retry'])->name('requests.retry');
});
