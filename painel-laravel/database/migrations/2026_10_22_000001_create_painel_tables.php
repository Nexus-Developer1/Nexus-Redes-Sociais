<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Posts criados pelo Claude (ou ideias de posts futuros).
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('caption')->nullable();
            $table->string('image_path')->nullable(); // relativo a public/
            $table->string('image_alt')->nullable();
            $table->json('networks');                 // ["Instagram","Facebook",...]
            $table->dateTime('scheduled_at')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('source')->nullable();       // de onde veio a ideia
            $table->text('change_request')->nullable(); // último pedido de alteração
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
        });

        // Histórico de todas as decisões (quem aprovou o quê e quando).
        Schema::create('post_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // approved, rejected, change_requested, undone
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Pedidos de novos posts feitos no painel.
        Schema::create('post_requests', function (Blueprint $table) {
            $table->id();
            $table->text('topic');
            $table->string('status')->default('new')->index(); // new, in_progress, done
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_requests');
        Schema::dropIfExists('post_reviews');
        Schema::dropIfExists('posts');
    }
};
