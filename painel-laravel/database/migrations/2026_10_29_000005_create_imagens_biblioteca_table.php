<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Biblioteca da NEXUS: fotos próprias, fotos oficiais de produtos e logótipos de parceiros.
        Schema::create('imagens_biblioteca', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20)->index(); // foto | produto | logo
            $table->string('descricao', 300);    // é o que a IA lê para escolher
            $table->string('ficheiro');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imagens_biblioteca');
    }
};
