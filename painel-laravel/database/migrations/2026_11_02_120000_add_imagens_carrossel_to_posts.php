<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Carrossel: todas as imagens do post, por ordem (a 1.ª é a capa, igual ao image_path). */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->json('imagens_carrossel')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('imagens_carrossel');
        });
    }
};
