<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
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

    /** Slides de conteúdo num carrossel (o Instagram aceita 10 imagens: capa + 8 + contactos). */
    public const MAX_SLIDES = 8;

    /** Número de imagens do post: 1, ou capa + slides + contactos num carrossel. */
    public static function totalImagens(array $design): int
    {
        return ! empty($design['carrossel']) && ! empty($design['slides']) ? count($design['slides']) + 2 : 1;
    }

    /**
     * Gera o(s) PNG e devolve ['path' => capa, relativo a public/, 'slides' => [todas as imagens, por ordem,
     * só num carrossel], 'story' => versão 9:16 para os Stories (ou null), 'avisos' => [...]].
     *
     * @throws RuntimeException se o browser não existir ou não gerar as imagens
     */
    public function render(Post $post): array
    {
        $design = $post->design ?? [];
        [$largura, $altura] = self::FORMATOS[$design['formato'] ?? '4:5'] ?? self::FORMATOS['4:5'];

        $browser = $this->browser();
        $pasta = self::pasta($post);
        File::ensureDirectoryExists($pasta);
        $sessao = $this->pastaDeTrabalho();
        $modelos = $this->fileUrl(public_path('modelos')).'/';
        // Nome com parte aleatória: ninguém consegue adivinhar o endereço da imagem de um post por aprovar.
        $carimbo = now()->format('YmdHis').'-'.strtolower(Str::random(8));
        $total = self::totalImagens($design);

        // Uma página por imagem: o modelos.js desenha a capa (slide 0), os slides e os contactos.
        $paginas = [];
        for ($i = 0; $i < $total; $i++) {
            $paginas[$i] = [$design + ($total > 1 ? ['slide' => $i] : []), $largura, $altura, $i ? '-'.($i + 1) : ''];
        }
        // Stories: a mesma capa em 9:16 (sem o "Deslize", que nos Stories não faz sentido).
        if ($post->temStories() && ($design['formato'] ?? '4:5') !== '9:16') {
            $story = array_diff_key($design, array_flip(['carrossel', 'slides', 'fecho']));
            $story['formato'] = '9:16';
            $paginas['story'] = [$story, ...self::FORMATOS['9:16'], '-story'];
        }

        $trabalhos = [];
        foreach ($paginas as $i => [$dados, $w, $h, $sufixo]) {
            // Os dados entram como JSON (o modelos.js põe-nos sempre como texto, nunca como HTML).
            // < > & escapados (<…): nenhum texto do post consegue fechar ou abrir tags dentro do <script>.
            $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
            $html = '<!doctype html><html lang="pt"><head><meta charset="utf-8">'
                .'<base href="'.$this->fileUrl($pasta).'/">'
                .'<link href="'.$modelos.'modelos.css" rel="stylesheet"></head><body>'
                .'<script>window.POST = '.$json.';</script>'
                .'<script src="'.$modelos.'modelos.js"></script></body></html>';
            $chave = count($trabalhos);
            $ficheiroHtml = "{$sessao}/pagina-{$chave}.html";
            File::put($ficheiroHtml, $html);
            $nome = "post-{$carimbo}{$sufixo}.png";
            $trabalhos[$i] = [
                'nome' => $nome,
                'png' => "{$pasta}/{$nome}",
                // o browser grava na pasta de trabalho; a imagem só passa para a pasta pública no fim
                'temporario' => "{$sessao}/imagem-{$chave}.png",
                'comando' => $this->comando($browser, $ficheiroHtml, "{$sessao}/imagem-{$chave}.png", $w, $h, "{$sessao}/perfil-{$chave}"),
            ];
        }

        try {
            // Até 3 browsers ao mesmo tempo: um carrossel de 10 imagens demora pouco mais do que 3.
            $registos = [];
            foreach (array_chunk($trabalhos, 3, true) as $grupo) {
                $resultados = Process::pool(function ($pool) use ($grupo, $sessao) {
                    foreach ($grupo as $i => $t) {
                        $pool->as((string) $i)->timeout(90)
                            ->env(['HOME' => $sessao, 'XDG_CONFIG_HOME' => $sessao, 'XDG_CACHE_HOME' => $sessao])
                            ->command($t['comando']);
                    }
                })->start()->wait();
                foreach ($grupo as $i => $t) {
                    $r = $resultados[(string) $i];
                    $registos[$i] = $r->output()."\n".$r->errorOutput();
                }
            }

            foreach ($trabalhos as $i => $t) {
                if (! is_file($t['temporario'])) {
                    $qual = $i === 'story' ? ' dos Stories' : ($total > 1 ? ' '.($i + 1) : '');
                    throw new RuntimeException('O browser não gerou a imagem'.$qual.'. '.trim(mb_substr($registos[$i], 0, 500)));
                }
            }
            foreach ($trabalhos as $t) {
                File::copy($t['temporario'], $t['png']);
            }
        } finally {
            File::deleteDirectory($sessao);
        }

        $avisos = [];
        foreach ($trabalhos as $i => $t) {
            $prefixo = $i === 'story' ? 'Stories: '
                : ($total > 1 ? ($i === 0 ? 'Capa: ' : ($i === $total - 1 ? 'Contactos: ' : 'Slide '.($i + 1).': ')) : '');
            if (! str_contains($registos[$i], 'NEXUS-ESTADO:pronto')) {
                $avisos[] = $prefixo.'O modelo não terminou de montar o post. Confirme a imagem antes de a enviar.';
            }
            preg_match_all('/"NEXUS-AVISO:(.*?)", source/s', $registos[$i], $m);
            foreach ($m[1] as $aviso) {
                $avisos[] = $prefixo.$aviso;
            }
        }

        // Apaga as imagens geradas antes destas (as imagens enviadas ficam).
        $novas = array_map(fn ($t) => str_replace('\\', '/', $t['png']), $trabalhos);
        foreach (File::glob("{$pasta}/post-*.png") as $antiga) {
            if (! in_array(str_replace('\\', '/', $antiga), $novas, true)) {
                File::delete($antiga);
            }
        }

        $caminhos = array_map(fn ($t) => "imagens-posts/design/{$post->id}/{$t['nome']}", $trabalhos);
        $story = $caminhos['story'] ?? null;
        unset($caminhos['story']);

        return ['path' => $caminhos[0], 'slides' => $total > 1 ? array_values($caminhos) : [], 'story' => $story, 'avisos' => $avisos];
    }

    /**
     * Pasta de trabalho só deste render (páginas HTML e imagens antes de irem para a pasta pública).
     * No servidor fica em RENDER_PASTA, partilhada com o utilizador que corre o browser (RENDER_UTILIZADOR),
     * que não consegue ler o .env nem o resto da aplicação. Cada render tem a sua: dois ao mesmo tempo não se atrapalham.
     */
    private function pastaDeTrabalho(): string
    {
        $base = config('services.render.pasta') ?: storage_path('app/render');
        File::ensureDirectoryExists($base);
        // restos de renders interrompidos (ex.: o servidor reiniciou a meio) saem ao fim de uma hora
        foreach (File::glob(rtrim($base, '/\\').'/r-*') as $antiga) {
            if (is_dir($antiga) && filemtime($antiga) < time() - 3600) {
                File::deleteDirectory($antiga);
            }
        }
        $sessao = rtrim($base, '/\\').DIRECTORY_SEPARATOR.'r-'.strtolower(Str::random(16));
        File::ensureDirectoryExists($sessao);
        if (config('services.render.utilizador')) {
            @chmod($sessao, 02770); // o grupo do browser escreve aqui (a pasta base tem o grupo dele)
        }

        return $sessao;
    }

    private function comando(string $browser, string $html, string $png, int $largura, int $altura, string $perfil): array
    {
        // No servidor o browser corre com um utilizador próprio, sem acesso às chaves da aplicação
        // (se um dia houver uma falha do Chrome numa imagem, quem a explorar não chega ao .env).
        $utilizador = config('services.render.utilizador');
        $comando = $utilizador ? ['sudo', '-n', '-u', $utilizador, $browser] : [$browser];
        if (str_contains(strtolower(basename($browser)), 'msedge') || str_contains(strtolower(basename($browser)), 'chrome.exe')) {
            $comando[] = '--headless=new';
        }
        array_push($comando,
            '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1',
            '--virtual-time-budget=10000', '--allow-file-access-from-files',
            "--window-size={$largura},{$altura}",
            '--enable-logging=stderr', '--v=0',
            '--screenshot='.$png,
        );
        if (! $utilizador) {
            // perfil só deste browser (o Edge do Windows precisa; com outro utilizador o Chrome usa um temporário)
            $comando[] = '--user-data-dir='.$perfil;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            // O utilizador da app não tem as permissões que a sandbox do Chrome pede; o conteúdo é só o nosso modelo.
            $comando[] = '--no-sandbox';
        }
        $comando[] = $this->fileUrl($html);

        return $comando;
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
            'cta' => 80, 'data' => 80, 'hora' => 40, 'fecho' => 80] as $campo => $max) {
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
        // Carrossel (feed: 4:5 ou 1:1): capa + um slide por ponto + contactos.
        if (! empty($d['carrossel']) && $limpo['formato'] !== '9:16' && is_array($d['slides'] ?? null)) {
            $slides = [];
            foreach (array_slice($d['slides'], 0, self::MAX_SLIDES) as $s) {
                if (! is_array($s)) {
                    continue;
                }
                $slide = array_filter([
                    'destaque' => $texto($s['destaque'] ?? '', 12),
                    'titulo' => $texto($s['titulo'] ?? '', 120),
                    'texto' => $texto($s['texto'] ?? '', 400),
                ], fn ($v) => $v !== '');
                if (isset($slide['titulo']) || isset($slide['texto'])) {
                    $slides[] = $slide;
                }
            }
            if ($slides) {
                $limpo['carrossel'] = true;
                $limpo['slides'] = $slides;
            }
        }
        if (empty($limpo['carrossel'])) {
            unset($limpo['fecho']);
        }
        // Para o botão "Outra foto": a pesquisa usada e as fotos do banco já usadas neste post.
        if (isset($d['pesquisa']) && ($v = $texto($d['pesquisa'], 80)) !== '') {
            $limpo['pesquisa'] = $v;
        }
        if (! empty($d['pesquisas']) && is_array($d['pesquisas'])) {
            $limpo['pesquisas'] = array_values(array_slice(array_filter(array_map(fn ($p) => $texto($p, 80), $d['pesquisas'])), -10));
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
