<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resultado da publicação automática na Meta (Facebook + Instagram).
        Schema::table('posts', function (Blueprint $table) {
            $table->string('facebook_post_id')->nullable();
            $table->string('instagram_media_id')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->text('publish_error')->nullable();
            $table->unsignedSmallInteger('publish_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['facebook_post_id', 'instagram_media_id', 'published_at', 'publish_error', 'publish_attempts']);
        });
    }
};
