<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Stories: versão 9:16 do post (gerada pelo servidor) e o ID da Story publicada no Instagram. */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('imagem_story')->nullable()->after('imagens_carrossel');
            $table->string('instagram_story_id')->nullable()->after('instagram_media_id');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['imagem_story', 'instagram_story_id']);
        });
    }
};
