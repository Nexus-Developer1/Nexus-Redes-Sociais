<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Gera a imagem PNG de um post a partir do modelo (public/modelos) e dos dados do post (posts.design).
 * Usa um Chrome/Edge sem janela: no servidor, o chrome-headless-shell em /opt; no Windows, o Edge.
 */
class PostRenderer
{
    public const MODELOS = ['dica', 'produto', 'aviso', 'evento', 'numero', 'frase', 'montra'];

    /** Nomes dos tipos de post como aparecem no painel. */
    public const NOMES = [
        'dica' => 'Dica', 'produto' => 'Produto', 'aviso' => 'Aviso', 'evento' => 'Evento',
        'numero' => 'Número em destaque', 'frase' => 'Frase', 'montra' => 'Produto em destaque',
    ];

    public const FORMATOS = ['4:5' => [1080, 1350], '1:1' => [1080, 1080], '9:16' => [1080, 1920]];

    public const ICONES = ['escudo', 'servidor', 'nuvem', 'rede', 'energia', 'monitor', 'chip'];

    /** Dados iniciais de um modelo (resources/modelos/<modelo>.json). */
    public static function exemplo(string $modelo): array
    {
        $f = resource_path("modelos/{$modelo}.json");

        return is_file($f) ? json_decode(File::get($f), true) : ['modelo' => $modelo, 'titulo' => ''];
    }

    /** Pasta pública com as imagens (fundo, produto, parceiro) e as imagens geradas do post. */
    public static function pasta(Post $post): string
    {
        return public_path("imagens-posts/design/{$post->id}");
    }

    public static function url(Post $post): string
    {
        return asset("imagens-posts/design/{$post->id}").'/';
    }

    /**
     * Gera o PNG e devolve ['path' => caminho relativo a public/, 'avisos' => [...]].
     *
     * @throws RuntimeException se o browser não existir ou não gerar a imagem
     */
    public function render(Post $post): array
    {
        $design = $post->design ?? [];
        [$largura, $altura] = self::FORMATOS[$design['formato'] ?? '4:5'] ?? self::FORMATOS['4:5'];

        $browser = $this->browser();
        $pasta = self::pasta($post);
        File::ensureDirectoryExists($pasta);
        $trabalho = storage_path('app/render');
        File::ensureDirectoryExists($trabalho);

        // Os dados entram como JSON (o modelos.js põe-nos sempre como texto, nunca como HTML).
        $json = str_replace('</', '<\/', json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $modelos = $this->fileUrl(public_path('modelos')).'/';
        $html = '<!doctype html><html lang="pt"><head><meta charset="utf-8">'
            .'<base href="'.$this->fileUrl($pasta).'/">'
            .'<link href="'.$modelos.'modelos.css" rel="stylesheet"></head><body>'
            .'<script>window.POST = '.$json.';</script>'
            .'<script src="'.$modelos.'modelos.js"></script></body></html>';
        $ficheiroHtml = "{$trabalho}/post-{$post->id}.html";
        File::put($ficheiroHtml, $html);

        $nome = 'post-'.now()->format('YmdHis').'.png';
        $png = "{$pasta}/{$nome}";

        $comando = [$browser];
        if (str_contains(strtolower(basename($browser)), 'msedge') || str_contains(strtolower(basename($browser)), 'chrome.exe')) {
            $comando[] = '--headless=new';
        }
        array_push($comando,
            '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1',
            '--virtual-time-budget=10000', '--allow-file-access-from-files',
            "--window-size={$largura},{$altura}",
            '--enable-logging=stderr', '--v=0',
            '--user-data-dir='.storage_path('app/render/perfil'),
            '--screenshot='.$png,
        );
        if (PHP_OS_FAMILY !== 'Windows') {
            // O utilizador da app não tem as permissões que a sandbox do Chrome pede; o conteúdo é só o nosso modelo.
            $comando[] = '--no-sandbox';
        }
        $comando[] = $this->fileUrl($ficheiroHtml);

        $resultado = Process::timeout(90)
            ->env(['HOME' => $trabalho, 'XDG_CONFIG_HOME' => $trabalho, 'XDG_CACHE_HOME' => $trabalho])
            ->run($comando);
        $registo = $resultado->output()."\n".$resultado->errorOutput();

        if (! is_file($png)) {
            throw new RuntimeException('O browser não gerou a imagem. '.trim(mb_substr($registo, 0, 500)));
        }

        // Apaga as imagens geradas antes desta (as imagens enviadas ficam).
        foreach (File::glob("{$pasta}/post-*.png") as $antiga) {
            if ($antiga !== $png) {
                File::delete($antiga);
            }
        }

        $avisos = [];
        if (! str_contains($registo, 'NEXUS-ESTADO:pronto')) {
            $avisos[] = 'O modelo não terminou de montar o post. Confirme a imagem antes de a enviar.';
        }
        preg_match_all('/"NEXUS-AVISO:(.*?)", source/s', $registo, $m);
        foreach ($m[1] as $aviso) {
            $avisos[] = $aviso;
        }

        return ['path' => "imagens-posts/design/{$post->id}/{$nome}", 'avisos' => $avisos];
    }

    /** Só guarda os campos que os modelos conhecem, com limites de tamanho (editor do painel e IA). */
    public static function limparDesign(array $d, Post $post): array
    {
        $texto = fn ($v, int $max) => is_scalar($v) ? mb_substr(trim(str_replace("\r", '', (string) $v)), 0, $max) : '';

        $limpo = [
            'modelo' => in_array($d['modelo'] ?? '', self::MODELOS, true) ? $d['modelo'] : 'dica',
            'formato' => isset(self::FORMATOS[$d['formato'] ?? '']) ? $d['formato'] : '4:5',
        ];
        foreach (['numero' => 4, 'titulo' => 300, 'texto' => 600, 'etiqueta' => 40, 'rodape' => 80, 'site' => 60,
            'cta' => 80, 'data' => 80, 'hora' => 40] as $campo => $max) {
            if (isset($d[$campo]) && ($v = $texto($d[$campo], $max)) !== '') {
                $limpo[$campo] = $v;
            }
        }
        // Foto de fundo (qualquer tipo): enquadramento (para não cortar caras) e intensidade do tom verde.
        if (in_array($d['enquadramento'] ?? '', ['topo', 'centro', 'baixo'], true)) {
            $limpo['enquadramento'] = $d['enquadramento'];
        }
        if (in_array($d['tom'] ?? '', ['nenhum', 'suave', 'forte'], true)) {
            $limpo['tom'] = $d['tom'];
        }
        if (in_array($d['icone'] ?? '', self::ICONES, true)) {
            $limpo['icone'] = $d['icone'];
        }
        if (! empty($d['pontos']) && is_array($d['pontos'])) {
            foreach (array_slice($d['pontos'], 0, 4) as $p) {
                if (is_array($p)) {
                    if (($t = $texto($p['texto'] ?? '', 90)) !== '') {
                        $limpo['pontos'][] = ['destaque' => $texto($p['destaque'] ?? '', 12), 'texto' => $t];
                    }
                } elseif (($v = $texto($p, 90)) !== '') {
                    $limpo['pontos'][] = $v;
                }
            }
        }
        if (! empty($d['evento']) && is_array($d['evento'])) {
            foreach (['dia' => 4, 'mes' => 12, 'hora' => 40, 'local' => 80] as $campo => $max) {
                if (($v = $texto($d['evento'][$campo] ?? '', $max)) !== '') {
                    $limpo['evento'][$campo] = $v;
                }
            }
        }
        // Para o botão "Outra foto": a pesquisa usada e as fotos do banco já usadas neste post.
        if (isset($d['pesquisa']) && ($v = $texto($d['pesquisa'], 80)) !== '') {
            $limpo['pesquisa'] = $v;
        }
        if (! empty($d['fotos_usadas']) && is_array($d['fotos_usadas'])) {
            $limpo['fotos_usadas'] = array_values(array_slice(array_unique(array_filter(array_map('intval', $d['fotos_usadas']))), -50));
        }
        // Imagens: só ficheiros que existem na pasta do post.
        foreach (['foto', 'fundo', 'produto', 'parceiro'] as $campo) {
            $nome = (string) ($d[$campo] ?? '');
            if (preg_match('/^[a-z0-9-]+\.(png|jpg|webp)$/', $nome) && is_file(self::pasta($post).'/'.$nome)) {
                $limpo[$campo] = $nome;
            }
        }

        return $limpo;
    }

    private function browser(): string
    {
        $configurado = config('services.render.browser');
        $candidatos = $configurado ? [$configurado] : [
            '/opt/chrome-headless-shell/chrome-headless-shell',
            'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
        ];
        foreach ($candidatos as $c) {
            if (is_file($c)) {
                return $c;
            }
        }

        throw new RuntimeException('Não encontrei o browser para gerar imagens (RENDER_BROWSER no .env).');
    }

    private function fileUrl(string $caminho): string
    {
        $caminho = str_replace('\\', '/', $caminho);

        $partes = array_map(
            fn ($p) => preg_match('/^[A-Za-z]:$/', $p) ? $p : rawurlencode($p), // "C:" fica como está
            explode('/', ltrim($caminho, '/'))
        );

        return 'file:///'.implode('/', $partes);
    }
}
