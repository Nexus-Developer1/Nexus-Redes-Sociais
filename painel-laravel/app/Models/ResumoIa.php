<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Resumo das notícias de IA de um período, escrito pela IA.
 * conteudo = ['titulo', 'introducao', 'destaques' => [['titulo', 'resumo', 'noticias' => [ids]]], 'para_a_nexus', 'ideias_posts' => [...]]
 */
class ResumoIa extends Model
{
    protected $table = 'resumos_ia';

    protected $fillable = ['inicio', 'fim', 'conteudo', 'n_noticias', 'modelo'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fim' => 'datetime', 'conteudo' => 'array'];
    }

    /** Notícias citadas nos destaques, por id (para os links). */
    public function noticias(): \Illuminate\Support\Collection
    {
        $ids = collect($this->conteudo['destaques'] ?? [])->pluck('noticias')->flatten()->filter()->unique()->all();

        return NoticiaIa::whereIn('id', $ids)->get()->keyBy('id');
    }
}
