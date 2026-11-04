<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Regras de segurança para as imagens que entram no painel (uploads e fotos dos bancos de imagens):
 * só imagens a sério, com um tamanho razoável, e descargas só dos servidores oficiais dos bancos.
 */
class ImagensSeguras
{
    /** Lado máximo de uma imagem (px): mais do que isto só gasta memória ao gerar o post. */
    public const MAX_LADO = 8000;

    /** Píxeis máximos (ex.: 6000×6000): uma imagem pequena em MB mas enorme em píxeis enche a memória. */
    public const MAX_PIXEIS = 40_000_000;

    /** Tamanho máximo de uma foto descarregada de um banco de imagens. */
    public const MAX_BYTES_DESCARGA = 15 * 1024 * 1024;

    /** Regras de validação para os uploads (editor e biblioteca). */
    public static function regrasUpload(): array
    {
        return ['dimensions:max_width='.self::MAX_LADO.',max_height='.self::MAX_LADO];
    }

    /** Confirma que é uma imagem JPEG/PNG/WEBP com dimensões razoáveis (lê só o cabeçalho, não a descodifica). */
    public static function verificar(string $bytes): void
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info[2] ?? null, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException('O ficheiro recebido não é uma imagem JPEG, PNG ou WEBP.');
        }
        if ($info[0] > self::MAX_LADO || $info[1] > self::MAX_LADO || $info[0] * $info[1] > self::MAX_PIXEIS) {
            throw new RuntimeException('A imagem é demasiado grande ('.$info[0].'×'.$info[1].' px).');
        }
    }

    /**
     * Descarrega uma foto de um banco de imagens: só https, só dos servidores indicados, sem seguir
     * redirecionamentos para outros sítios e com tamanho limitado. Devolve os bytes já verificados.
     *
     * @param  string[]  $servidores  ex.: ['pixabay.com'] aceita pixabay.com e cdn.pixabay.com
     */
    public static function descarregar(string $url, array $servidores): string
    {
        self::exigirServidor($url, $servidores);
        try {
            $resposta = self::pedido($url, $servidores);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // erro de rede ou descarga interrompida pelo limite de tamanho
            throw new RuntimeException('Não consegui descarregar a foto: '.$e->getMessage(), previous: $e);
        }
        if ($resposta->failed()) {
            throw new RuntimeException('Não consegui descarregar a foto (erro '.$resposta->status().').');
        }
        $bytes = $resposta->body();
        if (strlen($bytes) > self::MAX_BYTES_DESCARGA) {
            throw new RuntimeException('A foto é demasiado grande.');
        }
        self::verificar($bytes);

        return $bytes;
    }

    private static function pedido(string $url, array $servidores): \Illuminate\Http\Client\Response
    {
        return Http::timeout(60)
            ->withOptions([
                'allow_redirects' => [
                    'max' => 3,
                    'protocols' => ['https'],
                    // cada redirecionamento também tem de ficar nos servidores do banco
                    'on_redirect' => fn ($pedido, $resposta, $uri) => self::exigirServidor((string) $uri, $servidores),
                ],
                // pára de descarregar se passar do limite (mesmo que o servidor não diga o tamanho)
                'progress' => function ($total, $recebido) {
                    if ($total > self::MAX_BYTES_DESCARGA || $recebido > self::MAX_BYTES_DESCARGA) {
                        throw new RuntimeException('A foto é demasiado grande.');
                    }
                },
            ])
            ->get($url);
    }

    private static function exigirServidor(string $url, array $servidores): void
    {
        $partes = parse_url($url);
        $host = strtolower((string) ($partes['host'] ?? ''));
        $permitido = ($partes['scheme'] ?? '') === 'https' && collect($servidores)
            ->contains(fn ($s) => $host === $s || str_ends_with($host, '.'.$s));
        if (! $permitido) {
            throw new RuntimeException('Endereço de foto não permitido: '.($host ?: 'desconhecido').'.');
        }
    }
}
