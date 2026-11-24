<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada post tem sempre as duas vertentes: redes sociais (Instagram, Facebook e LinkedIn) e email aos parceiros.
 * O envio aos parceiros passa a vir ligado por defeito, e os posts ainda por aprovar ficam com as duas vertentes.
 * Os aprovados não mudam: foram aprovados sem o email, e nada sai sem aprovação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('enviar_parceiros')->default(true)->change();
        });

        $porAprovar = ['idea', 'creating', 'pending', 'revising'];
        DB::table('posts')->whereIn('status', $porAprovar)->update(['enviar_parceiros' => true]);
        foreach (DB::table('posts')->whereIn('status', $porAprovar)->get(['id', 'networks']) as $p) {
            $redes = json_decode((string) $p->networks, true) ?: [];
            $todas = array_values(array_intersect(['Instagram', 'Stories', 'Facebook', 'LinkedIn'],
                array_unique([...$redes, 'Instagram', 'Facebook', 'LinkedIn'])));
            if ($todas !== $redes) {
                // sem mexer no updated_at: é a "versão" dos links do email de aprovação
                DB::table('posts')->where('id', $p->id)->update(['networks' => json_encode($todas)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('enviar_parceiros')->default(false)->change();
        });
    }
};
