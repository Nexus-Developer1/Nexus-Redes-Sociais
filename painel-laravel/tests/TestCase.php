<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Um JPEG pequeno e verdadeiro (as descargas de fotos confirmam que é mesmo uma imagem). */
    protected function jpeg(int $largura = 40, int $altura = 50): string
    {
        $imagem = imagecreatetruecolor($largura, $altura);
        ob_start();
        imagejpeg($imagem);

        return (string) ob_get_clean();
    }
}
