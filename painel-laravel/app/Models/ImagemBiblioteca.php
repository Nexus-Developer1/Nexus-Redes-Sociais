<?php

namespace App\Models;

use App\Services\PostRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\File;

/** Uma imagem da biblioteca da NEXUS (foto própria, foto oficial de produto ou logótipo de parceiro). */
class ImagemBiblioteca extends Model
{
    protected $table = 'imagens_biblioteca';

    public const TIPOS = [
        'foto' => 'Fotos',
        'produto' => 'Produtos',
        'logo' => 'Logótipos de parceiros',
    ];

    /** Que tipo de imagem serve para cada campo do post. */
    public const PARA_CAMPO = ['foto' => 'foto', 'produto' => 'produto', 'parceiro' => 'logo'];

    protected $fillable = ['tipo', 'descricao', 'ficheiro', 'user_id'];

    public static function pasta(): string
    {
        return public_path('imagens-posts/biblioteca');
    }

    public function caminho(): string
    {
        return self::pasta().'/'.$this->ficheiro;
    }

    public function url(): string
    {
        return asset('imagens-posts/biblioteca/'.$this->ficheiro);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Copia a imagem para a pasta do post (os modelos só usam ficheiros dessa pasta). Devolve o nome. */
    public function copiarPara(Post $post): string
    {
        $ext = pathinfo($this->ficheiro, PATHINFO_EXTENSION);
        $nome = 'bib-'.$this->id.'.'.$ext;
        File::ensureDirectoryExists(PostRenderer::pasta($post));
        File::copy($this->caminho(), PostRenderer::pasta($post).'/'.$nome);

        return $nome;
    }

    protected static function booted(): void
    {
        // Ao remover da biblioteca, o ficheiro sai também (os posts já feitos têm a sua cópia).
        static::deleted(fn (self $img) => File::delete($img->caminho()));
    }
}
