<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fotos reais e gratuitas do Pixabay (uso comercial permitido, sem crédito obrigatório).
 * As regras do Pixabay pedem que as imagens sejam guardadas no nosso servidor (não ligadas
 * diretamente): é o que se faz, a foto fica na pasta do post.
 */
class Pixabay
{
    private const API = 'https://pixabay.com/api/';

    public function configurado(): bool
    {
        return filled(config('services.pixabay.key'));
    }

    /**
     * @return list<array{id: int, miniatura: string, alt: string, fotografo: string}>
     */
    public function procurar(string $pesquisa, string $formato = '4:5', int $quantos = 18): array
    {
        $resposta = $this->pedido([
            'q' => mb_substr($pesquisa, 0, 100),
            'image_type' => 'photo',
            'orientation' => $formato === '1:1' ? 'all' : 'vertical',
            'per_page' => max(3, min($quantos, 50)),
            'safesearch' => 'true',
            'min_width' => 800,
        ]);

        return collect($resposta['hits'] ?? [])->map(fn ($f) => [
            'id' => (int) $f['id'],
            'miniatura' => (string) ($f['webformatURL'] ?? $f['previewURL'] ?? ''),
            'alt' => (string) ($f['tags'] ?? ''),
            'fotografo' => (string) ($f['user'] ?? ''),
        ])->filter(fn ($f) => $f['miniatura'] !== '')->values()->all();
    }

    /** @return array{nome: string, fotografo: string} */
    public function descarregar(int $id, Post $post): array
    {
        $foto = $this->pedido(['id' => $id])['hits'][0] ?? null;
        $url = $foto['largeImageURL'] ?? null;
        if (! $url) {
            throw new RuntimeException('Não encontrei essa foto no Pixabay.');
        }

        $imagem = Http::timeout(60)->get($url);
        if ($imagem->failed() || ! str_starts_with((string) $imagem->header('Content-Type'), 'image/')) {
            throw new RuntimeException('Não consegui descarregar a foto do Pixabay.');
        }

        $nome = 'pixabay-'.$id.'.jpg';
        File::ensureDirectoryExists(PostRenderer::pasta($post));
        File::put(PostRenderer::pasta($post).'/'.$nome, $imagem->body());

        return ['nome' => $nome, 'fotografo' => (string) ($foto['user'] ?? '')];
    }

    private function pedido(array $parametros): array
    {
        if (! $this->configurado()) {
            throw new RuntimeException('O Pixabay não está configurado (PIXABAY_CHAVE no .env).');
        }
        $resposta = Http::timeout(20)->acceptJson()
            ->get(self::API, ['key' => config('services.pixabay.key'), 'lang' => 'pt'] + $parametros);
        if ($resposta->failed()) {
            throw new RuntimeException('O Pixabay respondeu com erro '.$resposta->status().'.');
        }

        return $resposta->json() ?? [];
    }
}
