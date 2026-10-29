<?php

use App\Http\Controllers\CalendarioController;
use App\Http\Controllers\ContaController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\PostDecisionController;
use App\Http\Controllers\PostEditorController;
use App\Http\Controllers\PostRequestController;
use App\Http\Controllers\UtilizadoresController;
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

    // A minha conta e gestão de utilizadores (esta só para administradores).
    Route::get('/conta', [ContaController::class, 'show'])->name('conta');
    Route::post('/conta', [ContaController::class, 'update'])->name('conta.update');
    Route::post('/conta/palavra-passe', [ContaController::class, 'password'])->name('conta.password')->middleware('throttle:10,1');
    Route::get('/utilizadores', [UtilizadoresController::class, 'index'])->name('users.index');
    Route::post('/utilizadores', [UtilizadoresController::class, 'store'])->name('users.store');
    Route::post('/utilizadores/{user}/palavra-passe', [UtilizadoresController::class, 'resetPassword'])->name('users.reset');
    Route::post('/utilizadores/{user}/admin', [UtilizadoresController::class, 'toggleAdmin'])->name('users.admin');
    Route::post('/utilizadores/{user}/remover', [UtilizadoresController::class, 'destroy'])->name('users.destroy');

    // Criar e editar posts no painel (modelos + legenda + data).
    Route::get('/posts/novo', [PostEditorController::class, 'create'])->name('posts.create');
    Route::post('/posts', [PostEditorController::class, 'store'])->name('posts.store');
    Route::get('/posts/{post}/editar', [PostEditorController::class, 'edit'])->name('posts.edit');
    Route::post('/posts/{post}/editar', [PostEditorController::class, 'update'])->name('posts.update');
    Route::post('/posts/{post}/modelo', [PostEditorController::class, 'useTemplate'])->name('posts.template');
    Route::post('/posts/{post}/imagens', [PostEditorController::class, 'upload'])->name('posts.upload');
    Route::post('/posts/{post}/enviar', [PostEditorController::class, 'submit'])->name('posts.submit');
    Route::post('/posts/{post}/data', [PostEditorController::class, 'reschedule'])->name('posts.date');
    Route::get('/imagens/procurar', [PostEditorController::class, 'searchImages'])->name('images.search')->middleware('throttle:60,1');
    Route::post('/posts/{post}/imagens/pexels', [PostEditorController::class, 'useStockImage'])->name('posts.stock');
    Route::post('/posts/{post}/legenda-ia', [PostEditorController::class, 'captionAi'])->name('posts.captionAi')->middleware('throttle:30,1');
    Route::post('/posts/{post}/outra-foto', [PostEditorController::class, 'otherPhoto'])->name('posts.otherPhoto')->middleware('throttle:20,1');

    Route::post('/pedidos', [PostRequestController::class, 'store'])->name('requests.store');
    Route::post('/pedidos/{pedido}/repetir', [PostRequestController::class, 'retry'])->name('requests.retry');
});
