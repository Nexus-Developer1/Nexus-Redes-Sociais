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
        $parametros = [
            'q' => mb_substr($pesquisa, 0, 100),
            'image_type' => 'photo',
            'orientation' => 'all', // o post corta a foto ao tamanho certo; só verticais dava poucos resultados
            'per_page' => max(3, min($quantos, 50)),
            'safesearch' => 'true',
            'min_width' => 800,
        ];
        // A IA pesquisa em inglês; quem usa o editor pode escrever em português.
        // Pesquisa primeiro na língua que parece ser a da pesquisa e completa com a outra se vier pouco.
        [$primeira, $segunda] = self::parecePortugues($pesquisa) ? ['pt', 'en'] : ['en', 'pt'];
        $hits = $this->pedido($parametros + ['lang' => $primeira])['hits'] ?? [];
        if (count($hits) < $quantos / 2) {
            $hits = array_merge($hits, $this->pedido($parametros + ['lang' => $segunda])['hits'] ?? []);
        }

        return collect($hits)->unique('id')->take($quantos)->map(fn ($f) => [
            'id' => (int) $f['id'],
            'miniatura' => (string) ($f['webformatURL'] ?? $f['previewURL'] ?? ''),
            'alt' => (string) ($f['tags'] ?? ''),
            'fotografo' => (string) ($f['user'] ?? ''),
        ])->filter(fn ($f) => $f['miniatura'] !== '')->values()->all();
    }

    public static function parecePortugues(string $texto): bool
    {
        return (bool) preg_match('/[áàâãéêíóôõúç]|\b(de|da|do|das|dos|com|para|em|uma?|equipa|escrit\w*|servidor\w*|reuni\w*|trabalh\w*|empresa|pessoas?)\b/iu', $texto);
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
            ->get(self::API, ['key' => config('services.pixabay.key')] + $parametros);
        if ($resposta->failed()) {
            throw new RuntimeException('O Pixabay respondeu com erro '.$resposta->status().'.');
        }

        return $resposta->json() ?? [];
    }
}
