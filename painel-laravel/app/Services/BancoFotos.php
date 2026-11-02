<?php

namespace App\Services;

use App\Models\Post;
use RuntimeException;

/**
 * Banco de fotos grátis para as imagens de fundo: usa o Pexels se tiver chave, senão o Pixabay.
 * (O Pexels suspendeu chaves novas em out/2026; o Pixabay dá a chave na hora.)
 */
class BancoFotos
{
    public function __construct(private Pexels $pexels, private Pixabay $pixabay) {}

    public function configurado(): bool
    {
        return $this->fornecedor() !== null;
    }

    public function nome(): string
    {
        return $this->pexels->configurado() ? 'Pexels' : 'Pixabay';
    }

    /** @return list<array{id: int, miniatura: string, alt: string, fotografo: string}> */
    public function procurar(string $pesquisa, string $formato = '4:5', int $quantos = 18): array
    {
        return $this->obrigatorio()->procurar($pesquisa, $formato, $quantos);
    }

    /**
     * Como procurar(), mas sem as fotos que já estão noutros posts: a mesma foto nunca aparece
     * em dois posts diferentes. $post = o post para que é a foto (as dele contam como livres).
     *
     * @return list<array{id: int, miniatura: string, alt: string, fotografo: string}>
     */
    public function procurarNovas(string $pesquisa, string $formato = '4:5', int $quantos = 18, ?Post $post = null): array
    {
        $emUso = Post::fotosEmUso($post?->id)['banco'];
        $fotos = array_filter($this->procurar($pesquisa, $formato, min(50, $quantos * 2)),
            fn ($f) => ! in_array($this->chave($f['id']), $emUso, true));

        return array_slice(array_values($fotos), 0, $quantos);
    }

    /** Nome com que a foto fica guardada no post, sem extensão (ex.: "pixabay-123"). */
    public function chave(int $id): string
    {
        return strtolower($this->nome()).'-'.$id;
    }

    /** @return array{nome: string, fotografo: string} */
    public function descarregar(int $id, Post $post): array
    {
        return $this->obrigatorio()->descarregar($id, $post);
    }

    private function fornecedor(): Pexels|Pixabay|null
    {
        return $this->pexels->configurado() ? $this->pexels : ($this->pixabay->configurado() ? $this->pixabay : null);
    }

    private function obrigatorio(): Pexels|Pixabay
    {
        return $this->fornecedor() ?? throw new RuntimeException('Não há banco de fotos configurado (PIXABAY_CHAVE ou PEXELS_CHAVE).');
    }
}
