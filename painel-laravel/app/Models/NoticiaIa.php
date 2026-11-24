<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma notícia sobre IA recolhida de um feed (RSS/Atom). O texto vem de fora: só se mostra como texto. */
class NoticiaIa extends Model
{
    protected $table = 'noticias_ia';

    protected $fillable = ['fonte', 'titulo', 'link', 'descricao', 'publicada_em'];

    protected function casts(): array
    {
        return ['publicada_em' => 'datetime'];
    }
}
