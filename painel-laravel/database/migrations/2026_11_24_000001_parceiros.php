<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posts também por email aos parceiros: a lista de parceiros, a escolha em cada post
 * ("Enviar também aos parceiros") e o registo de cada envio (um por parceiro: nunca sai duas vezes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parceiros', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 150);
            $table->string('empresa', 150)->nullable();
            $table->string('email', 190)->unique();
            $table->boolean('ativo')->default(true);
            $table->timestamp('saiu_em')->nullable(); // pediu para deixar de receber
            $table->timestamps();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('enviar_parceiros')->default(false);
            $table->timestamp('parceiros_enviado_em')->nullable();
        });

        Schema::create('parceiro_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parceiro_id')->constrained('parceiros')->cascadeOnDelete();
            $table->timestamp('enviado_em')->nullable();
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->string('erro', 300)->nullable();
            $table->timestamps();
            $table->unique(['post_id', 'parceiro_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parceiro_envios');
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['enviar_parceiros', 'parceiros_enviado_em']);
        });
        Schema::dropIfExists('parceiros');
    }
};
