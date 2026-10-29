<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_requests', function (Blueprint $table) {
            // O post criado a partir do pedido e, se a IA falhar, o motivo.
            $table->foreignId('post_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->text('error')->nullable()->after('post_id');
        });
    }

    public function down(): void
    {
        Schema::table('post_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('post_id');
            $table->dropColumn('error');
        });
    }
};
