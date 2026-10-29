<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fotos reais e gratuitas do Pexels (uso comercial permitido, sem crédito obrigatório).
 * Serve para a imagem de fundo dos posts: procurar no editor ou escolher sozinho nos posts da IA.
 */
class Pexels
{
    private const API = 'https://api.pexels.com/v1';

    public function configurado(): bool
    {
        return filled(config('services.pexels.key'));
    }

    /**
     * @return list<array{id: int, miniatura: string, alt: string, fotografo: string}>
     */
    public function procurar(string $pesquisa, string $formato = '4:5', int $quantos = 18): array
    {
        $resposta = $this->pedido()->get(self::API.'/search', [
            'query' => $pesquisa,
            'orientation' => $formato === '1:1' ? 'square' : 'portrait',
            'per_page' => $quantos,
            'size' => 'large',
        ]);
        if ($resposta->failed()) {
            throw new RuntimeException('O Pexels respondeu com erro '.$resposta->status().'.');
        }

        return collect($resposta->json('photos', []))->map(fn ($f) => [
            'id' => (int) $f['id'],
            'miniatura' => (string) ($f['src']['medium'] ?? ''),
            'alt' => (string) ($f['alt'] ?? ''),
            'fotografo' => (string) ($f['photographer'] ?? ''),
        ])->filter(fn ($f) => $f['miniatura'] !== '')->values()->all();
    }

    /**
     * Descarrega a foto (já cortada no tamanho do post) para a pasta do post.
     *
     * @return array{nome: string, fotografo: string}
     */
    public function descarregar(int $id, Post $post): array
    {
        $resposta = $this->pedido()->get(self::API.'/photos/'.$id);
        if ($resposta->failed() || ! ($original = $resposta->json('src.original'))) {
            throw new RuntimeException('Não encontrei essa foto no Pexels.');
        }

        [$largura, $altura] = PostRenderer::FORMATOS[$post->design['formato'] ?? '4:5'] ?? PostRenderer::FORMATOS['4:5'];
        // A CDN do Pexels corta e comprime: chega uma foto do tamanho certo, sem guardar o original enorme.
        $url = $original.'?auto=compress&cs=tinysrgb&fit=crop&w='.$largura.'&h='.$altura;
        $imagem = Http::timeout(60)->get($url);
        if ($imagem->failed() || ! str_starts_with((string) $imagem->header('Content-Type'), 'image/')) {
            throw new RuntimeException('Não consegui descarregar a foto do Pexels.');
        }

        $nome = 'pexels-'.$id.'.jpg';
        File::ensureDirectoryExists(PostRenderer::pasta($post));
        File::put(PostRenderer::pasta($post).'/'.$nome, $imagem->body());

        return ['nome' => $nome, 'fotografo' => (string) $resposta->json('photographer', '')];
    }

    private function pedido()
    {
        if (! $this->configurado()) {
            throw new RuntimeException('O Pexels não está configurado (PEXELS_CHAVE no .env).');
        }

        return Http::withHeaders(['Authorization' => config('services.pexels.key')])->timeout(20)->acceptJson();
    }
}
