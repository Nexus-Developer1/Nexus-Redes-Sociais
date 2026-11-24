<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Miniaturas para o painel: os cartões mostravam a imagem inteira (1080×1350, ~0,6 MB cada; na biblioteca,
 * fotos de até 20 MB). A miniatura é um JPEG pequeno feito na primeira vez que é pedido e guardado em
 * public/imagens-posts/mini/. O nome inclui a data do ficheiro: quando a imagem muda, faz-se uma nova.
 * Clicar na imagem continua a abrir o original.
 */
class Miniatura
{
    public const PASTA = 'imagens-posts/mini';

    /** Endereço da miniatura (ou do original, se não der para a fazer). */
    public static function url(?string $caminho, int $largura = 480): string
    {
        if (! $caminho) {
            return '';
        }
        $relativo = self::relativo($caminho, $largura);

        return asset($relativo);
    }

    /** Ficheiro da miniatura no disco (ou do original), ex.: para ir dentro de um email. Null se não existir. */
    public static function caminho(?string $caminho, int $largura = 480): ?string
    {
        if (! $caminho || ! is_file(public_path($caminho))) {
            return null;
        }

        return public_path(self::relativo($caminho, $largura));
    }

    /** Caminho (relativo a public/) da miniatura, feita na primeira vez; o original se não der para a fazer. */
    private static function relativo(string $caminho, int $largura): string
    {
        $original = public_path($caminho);
        if (! is_file($original) || ! function_exists('imagecreatefromstring')) {
            return $caminho;
        }

        $nome = md5($caminho.'|'.filemtime($original).'|'.$largura).'.jpg';
        $destino = public_path(self::PASTA.'/'.$nome);
        if (! is_file($destino) && ! self::fazer($original, $destino, $largura)) {
            return $caminho;
        }

        return self::PASTA.'/'.$nome;
    }

    private static function fazer(string $original, string $destino, int $largura): bool
    {
        try {
            // Imagens enormes (mais de ~40 megapíxeis) ficam como estão: não vale a pena gastar a memória.
            [$w, $h] = @getimagesize($original) ?: [0, 0];
            if ($w <= 0 || $h <= 0 || $w * $h > 40_000_000) {
                return false;
            }
            if ($w <= $largura) {
                return false; // já é pequena
            }
            $img = @imagecreatefromstring((string) file_get_contents($original));
            if (! $img) {
                return false;
            }
            $altura = (int) round($h * $largura / $w);
            $nova = imagecreatetruecolor($largura, $altura);
            // fundo branco para PNG com transparência (logótipos), que em JPEG ficaria preto
            imagefill($nova, 0, 0, imagecolorallocate($nova, 255, 255, 255));
            imagecopyresampled($nova, $img, 0, 0, 0, 0, $largura, $altura, $w, $h);
            File::ensureDirectoryExists(dirname($destino));
            $temp = $destino.'.'.getmypid().'.tmp';
            $ok = imagejpeg($nova, $temp, 82) && rename($temp, $destino);
            imagedestroy($img);
            imagedestroy($nova);

            return $ok;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
