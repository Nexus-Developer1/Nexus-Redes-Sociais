<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para as consultas que o painel e as tarefas automáticas fazem a toda a hora
 * (o PostgreSQL não cria índices nas chaves estrangeiras sozinho), e a hora do lembrete
 * enviado quando um post continua à espera de aprovação perto da data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->index(['status', 'scheduled_at']);
            $table->timestamp('lembrete_em')->nullable();
        });
        Schema::table('post_reviews', function (Blueprint $table) {
            $table->index('post_id');
        });
        Schema::table('post_requests', function (Blueprint $table) {
            $table->index(['post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('post_requests', function (Blueprint $table) {
            $table->dropIndex(['post_id', 'status']);
        });
        Schema::table('post_reviews', function (Blueprint $table) {
            $table->dropIndex(['post_id']);
        });
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropColumn('lembrete_em');
        });
    }
};
