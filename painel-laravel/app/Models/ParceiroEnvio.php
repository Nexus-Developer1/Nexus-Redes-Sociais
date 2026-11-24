<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um email de um post a um parceiro: enviado (enviado_em) ou as tentativas falhadas. Um só por post e parceiro. */
class ParceiroEnvio extends Model
{
    protected $table = 'parceiro_envios';

    protected $fillable = ['post_id', 'parceiro_id', 'enviado_em', 'tentativas', 'erro'];

    protected function casts(): array
    {
        return ['enviado_em' => 'datetime'];
    }

    public function parceiro(): BelongsTo
    {
        return $this->belongsTo(Parceiro::class);
    }
}
