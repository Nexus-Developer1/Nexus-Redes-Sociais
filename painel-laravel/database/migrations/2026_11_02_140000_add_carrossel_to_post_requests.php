<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Quem pede o post escolhe se é carrossel (a IA nunca decide isso sozinha). */
    public function up(): void
    {
        Schema::table('post_requests', function (Blueprint $table) {
            $table->boolean('carrossel')->default(false)->after('publish_at');
        });
    }

    public function down(): void
    {
        Schema::table('post_requests', function (Blueprint $table) {
            $table->dropColumn('carrossel');
        });
    }
};
