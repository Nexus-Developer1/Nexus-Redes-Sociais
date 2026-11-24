<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma notícia recolhida de um feed (RSS/Atom): de IA ou de um parceiro tecnológico da NEXUS (`parceiro`, e então
 * `fonte` = a marca). O texto vem de fora: só se mostra como texto.
 */
class NoticiaIa extends Model
{
    protected $table = 'noticias_ia';

    protected $fillable = ['fonte', 'titulo', 'link', 'descricao', 'publicada_em', 'parceiro'];

    protected function casts(): array
    {
        return ['publicada_em' => 'datetime', 'parceiro' => 'boolean'];
    }

    public function scopeDeIa($q)
    {
        return $q->where('parceiro', false);
    }

    public function scopeDeParceiros($q)
    {
        return $q->where('parceiro', true);
    }
}
