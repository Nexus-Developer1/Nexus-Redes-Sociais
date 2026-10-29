<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_requests', function (Blueprint $table) {
            // Dia e hora em que o post deve ser publicado, escolhidos ao pedir (vazio = a IA escolhe).
            $table->dateTime('publish_at')->nullable()->after('topic');
        });
    }

    public function down(): void
    {
        Schema::table('post_requests', function (Blueprint $table) {
            $table->dropColumn('publish_at');
        });
    }
};
