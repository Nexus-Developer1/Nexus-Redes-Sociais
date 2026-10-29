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
            'per_page' => 50,
            'safesearch' => 'true',
            'min_width' => 800,
        ];
        // A IA pesquisa em inglês; quem usa o editor pode escrever em português.
        // Pesquisa primeiro na língua que parece ser a da pesquisa e completa com a outra se vier pouco.
        // Primeiro só fotos verticais (ficam nítidas no post 4:5); depois as outras, que passam pelo filtro de nitidez.
        [$primeira, $segunda] = self::parecePortugues($pesquisa) ? ['pt', 'en'] : ['en', 'pt'];
        $hits = [];
        foreach ([[$primeira, 'vertical'], [$primeira, 'all'], [$segunda, 'vertical'], [$segunda, 'all']] as [$lang, $orientacao]) {
            $nitidas = collect($hits)->filter(fn ($f) => self::ampliacao($f, $formato) <= self::AMPLIACAO_NITIDA)->count();
            if ($nitidas >= $quantos) {
                break;
            }
            $hits = array_merge($hits, $this->pedido($parametros + ['lang' => $lang, 'orientation' => $orientacao])['hits'] ?? []);
        }

        // Só fotos que ficam nítidas no tamanho do post; as que obrigariam a ampliar muito ficam de fora.
        return collect($hits)->unique('id')
            ->filter(fn ($f) => self::ampliacao($f, $formato) <= self::AMPLIACAO_NITIDA)
            ->take($quantos)->map(fn ($f) => [
            'id' => (int) $f['id'],
            'miniatura' => (string) ($f['webformatURL'] ?? $f['previewURL'] ?? ''),
            'alt' => (string) ($f['tags'] ?? ''),
            'fotografo' => (string) ($f['user'] ?? ''),
        ])->filter(fn ($f) => $f['miniatura'] !== '')->values()->all();
    }

    /** Ampliação máxima aceite: acima disto a foto fica visivelmente desfocada. */
    public const AMPLIACAO_NITIDA = 1.3;

    /**
     * Quanto a foto teria de ser ampliada para encher o post. O Pixabay gratuito dá no máximo
     * 1280 px no lado maior (largeImageURL); um post 4:5 tem 1080×1350.
     */
    public static function ampliacao(array $foto, string $formato): float
    {
        $w = (int) ($foto['imageWidth'] ?? 0);
        $h = (int) ($foto['imageHeight'] ?? 0);
        if ($w <= 0 || $h <= 0) {
            return 99.0;
        }
        $f = min(1, 1280 / max($w, $h));
        [$alvoW, $alvoH] = PostRenderer::FORMATOS[$formato] ?? PostRenderer::FORMATOS['4:5'];

        return max($alvoW / ($w * $f), $alvoH / ($h * $f));
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
