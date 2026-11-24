<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notícias dos parceiros tecnológicos da NEXUS (AMD, Intel, NVIDIA, Supermicro…) na página "Notícias de IA",
 * no separador "Parceiros". Ficam na mesma tabela, marcadas: não entram no resumo da IA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noticias_ia', function (Blueprint $table) {
            $table->boolean('parceiro')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('noticias_ia', function (Blueprint $table) {
            $table->dropIndex(['parceiro']);
            $table->dropColumn('parceiro');
        });
    }
};
