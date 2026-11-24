<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Página "Notícias de IA": as notícias que o agente recolhe dos feeds (RSS/Atom) e os resumos que a IA faz
 * duas vezes por semana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('noticias_ia', function (Blueprint $table) {
            $table->id();
            $table->string('fonte', 80);
            $table->string('titulo', 300);
            $table->string('link', 700)->unique();
            $table->text('descricao')->nullable();
            $table->timestamp('publicada_em')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('resumos_ia', function (Blueprint $table) {
            $table->id();
            $table->timestamp('inicio');
            $table->timestamp('fim');
            $table->json('conteudo');            // título, introdução, destaques (com as notícias de origem), para a NEXUS, ideias
            $table->unsignedSmallInteger('n_noticias')->default(0);
            $table->string('modelo', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resumos_ia');
        Schema::dropIfExists('noticias_ia');
    }
};
