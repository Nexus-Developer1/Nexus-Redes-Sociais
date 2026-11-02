<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Escreve posts com uma IA (API no formato OpenAI, ex.: Groq): a partir de um tema
 * ou de um pedido de alteração devolve os textos do modelo, a legenda, as redes e a data.
 * A imagem é sempre montada depois pelo PostRenderer, nunca gerada pela IA.
 */
class PostWriter
{
    public const EMPRESA = <<<'TXT'
        NEXUS Solutions / NEXUS IT Solutions: empresa portuguesa de tecnologias de informação, fundada em 2010.
        Site: nexus-solutions.pt. Contacto: info@nexus-solutions.pt, 229 437 980.
        Produtos e serviços: PCs, mini-PCs, POS, workstations (3D, CAD, render), servidores e storage, GPUs NVIDIA,
        Supermicro, alta disponibilidade, backup e disaster recovery, cibersegurança, videoconferência, datacenters,
        redes, UPS Riello, bastidores, consultoria e suporte, programa de revendedores.
        Clientes: sobretudo empresas (B2B). Tom: profissional, próximo e direto, sem exageros.
        TXT;

    public function configurado(): bool
    {
        return filled(config('ia.chave'));
    }

    /** Post novo a partir do tema escrito em "Pedir um post" (e, se escolhida, a data de publicação). */
    public function criar(string $tema, ?Carbon $publicarEm = null): array
    {
        $data = $publicarEm
            ? "\nA data de publicação já está decidida: ".$publicarEm->locale('pt_PT')->translatedFormat('l, j \d\e F \d\e Y, H:i')
              .'. Usa-a em "publicar_em" e não a mudes. (A data do acontecimento, se houver, é a que vem no tema.)'
            : '';

        $r = $this->pedir("Pedido de post: \"{$tema}\"{$data}\n".$this->catalogo()."\nCria o post completo.");
        if ($publicarEm) {
            $r['publicar_em'] = $publicarEm->copy(); // a escolha de quem pediu manda sempre
        }

        return $r;
    }

    /**
     * Produtos e logótipos de parceiros que existem na biblioteca da NEXUS, para a IA os poder usar
     * (com o id). Vazio se a biblioteca não tiver nenhum.
     */
    private function catalogo(): string
    {
        $produtos = \App\Models\ImagemBiblioteca::where('tipo', 'produto')->latest()->limit(40)->get();
        $logos = \App\Models\ImagemBiblioteca::where('tipo', 'logo')->latest()->limit(40)->get();
        if ($produtos->isEmpty() && $logos->isEmpty()) {
            return '';
        }
        $linhas = fn ($c) => $c->map(fn ($i) => "  {$i->id}: {$i->descricao}")->implode("\n");

        return "\nIMAGENS OFICIAIS DA NEXUS (biblioteca):\n"
            .($produtos->isNotEmpty() ? "Produtos:\n".$linhas($produtos)."\n" : '')
            .($logos->isNotEmpty() ? "Logótipos de parceiros:\n".$linhas($logos)."\n" : '')
            .'Se o post for sobre um destes produtos, usa o modelo "montra" (ou "produto") e põe o id em "produto_biblioteca". '
            .'Se o produto ou o tema for de uma destas marcas, põe o id do logótipo em "parceiro_biblioteca". '
            ."Se nenhum servir, 0. Nunca uses um produto que não corresponda ao tema.\n";
    }

    /** Nova versão de um post, com o que o aprovador pediu para mudar. */
    public function rever(Post $post, string $alteracao): array
    {
        $atual = json_encode([
            'titulo_interno' => $post->title,
            'design' => $post->design,
            'legenda' => $post->caption,
            'redes' => $post->networks,
            'publicar_em' => $post->scheduled_at?->format('Y-m-d H:i'),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $this->pedir(
            "Post atual:\n{$atual}\n\nO aprovador pediu esta alteração: \"{$alteracao}\"\n\n"
            .'Devolve o post completo com a alteração feita. Mantém tudo o resto igual, a não ser que a alteração o obrigue a mudar.'
        );
    }

    /**
     * Entre as fotos encontradas no banco de fotos, escolhe a que serve de fundo para o post
     * (pela descrição de cada uma). Devolve null se nenhuma servir: mais vale sem foto do que errada.
     *
     * @param  list<array{id: int, alt: string}>  $fotos
     */
    public function escolherFoto(string $tema, array $fotos): ?int
    {
        if (! $fotos) {
            return null;
        }
        $lista = collect($fotos)->map(fn ($f) => $f['id'].': '.mb_substr($f['alt'], 0, 150))->implode("\n");
        $resposta = $this->chamar(30, [
                'messages' => [
                    ['role' => 'system', 'content' => 'Escolhes a fotografia de fundo para um post de uma empresa portuguesa de informática '
                        .'(servidores, computadores, redes, energia/UPS, cibersegurança, escritórios). Cada linha é "id: palavras que descrevem a foto". '
                        .'Escolhe a que melhor combina com o tema e que mostre claramente tecnologia, equipamento, escritórios ou pessoas a trabalhar. '
                        .'Comida, animais, paisagens, desporto ou qualquer coisa sem relação NÃO servem. Se nenhuma servir, responde 0. '
                        .'Responde só com o número.'],
                    ['role' => 'user', 'content' => "Tema do post: {$tema}\n\nFotos:\n{$lista}"],
                ],
                'temperature' => 0,
            ]);
        if ($resposta->failed()) {
            return null;
        }
        preg_match('/\d+/', (string) $resposta->json('choices.0.message.content'), $m);
        $id = (int) ($m[0] ?? 0);

        return in_array($id, array_column($fotos, 'id'), true) ? $id : null;
    }

    /**
     * Temas para o calendário: até $quantas ideias entre $inicio e $fim (dias úteis), a pensar nas datas
     * especiais desse período, nos produtos e serviços da NEXUS e sem repetir temas recentes.
     * Com $pedido (ex.: "Trocar tema": "prefiro algo sobre redes") propõe uma ideia para esse pedido.
     *
     * @param  array<string, string>  $datas  'AAAA-MM-DD' => "Feriado: …" ou nome do dia especial (DatasEspeciais)
     * @param  string[]  $recentes  títulos de posts recentes e já marcados (para não repetir)
     * @param  string[]  $ocupados  dias ('AAAA-MM-DD') que já têm post
     * @return list<array{titulo: string, tipo: string, notas: string, quando: Carbon}>
     */
    public function proporIdeias(Carbon $inicio, Carbon $fim, int $quantas, array $datas, array $recentes, array $ocupados = [], ?string $pedido = null): array
    {
        if (! $this->configurado()) {
            throw new RuntimeException('A IA não está configurada (IA_CHAVE no .env).');
        }
        if ($quantas < 1) {
            return [];
        }

        $f = fn (Carbon $d) => $d->locale('pt_PT')->translatedFormat('l, j \d\e F \d\e Y');
        $lista = fn (array $l) => $l ? '- '.implode("\n- ", $l) : '(nenhum)';
        $especiais = collect($datas)->map(fn ($nome, $dia) => $f(Carbon::parse($dia)).": {$nome}")->values()->all();
        $tipos = implode(', ', \App\Console\Commands\CriarIdeia::TIPOS);
        $periodo = $inicio->isSameDay($fim) ? $f($inicio) : 'de '.$f($inicio).' a '.$f($fim);

        $mensagem = ($pedido
            ? "Quem aprova os posts pediu para trocar o tema do post de {$periodo}. O que pediu: \"{$pedido}\"\nPropõe 1 tema novo que responda a esse pedido."
            : "Propõe {$quantas} temas de posts para {$periodo}.")
            ."\n\nDIAS ESPECIAIS NESTE PERÍODO:\n".$lista($especiais)
            ."\n\nDIAS QUE JÁ TÊM POST (evita-os):\n".$lista(array_map(fn ($d) => $f(Carbon::parse($d)), $ocupados))
            ."\n\nTEMAS RECENTES OU JÁ MARCADOS (não repitas nem faças parecido):\n".$lista(array_slice($recentes, 0, 60))
            .$this->catalogo();

        $sistema = 'És o responsável pelo calendário das redes sociais (Instagram, Facebook e LinkedIn) da NEXUS IT Solutions. '
            .'Hoje é '.now()->locale('pt_PT')->translatedFormat('l, j \d\e F \d\e Y').".\n\nA EMPRESA\n".self::EMPRESA."\n\n"
            ."COMO ESCOLHER OS TEMAS\n"
            ."- Varia: mistura dicas úteis para empresas (segurança, backup, redes, produtividade), produtos e serviços da NEXUS e, se houver, os dias especiais.\n"
            ."- Num feriado nacional a empresa está fechada: o post é um aviso (tipo \"aviso\") publicado no dia útil anterior. Não marques outros posts para o feriado.\n"
            ."- Um dia especial (ex.: Dia Mundial do Backup) dá um bom post nesse dia.\n"
            ."- Nunca inventes eventos, promoções, preços ou novidades que não conheces: o tipo \"evento\" só se o pedido falar de um evento.\n"
            ."- Dias úteis (segunda a sexta), espalhados pelo período, às 10:00 (ou 18:00 se fizer mais sentido).\n"
            ."- Escreve em português de Portugal (nunca do Brasil).\n\n"
            ."RESPONDE SÓ com um objeto JSON:\n"
            .'{"ideias": [{"titulo": "tema curto e claro, ex.: Dica: 3 sinais de phishing num email", '
            ."\"tipo\": \"{$tipos}\", \"notas\": \"1 ou 2 frases: o que o post deve dizer e porquê agora\", \"quando\": \"AAAA-MM-DD HH:MM\"}]}";

        $ideias = null;
        for ($tentativa = 1; $tentativa <= 2 && ! $ideias; $tentativa++) {
            $resposta = $this->chamar(config('ia.timeout'), [
                'messages' => [['role' => 'system', 'content' => $sistema], ['role' => 'user', 'content' => $mensagem]],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.8,
            ]);
            if ($resposta->failed()) {
                throw new RuntimeException('A IA respondeu com erro '.$resposta->status().': '
                    .mb_substr((string) ($resposta->json('error.message') ?? $resposta->body()), 0, 300));
            }
            $dados = json_decode((string) $resposta->json('choices.0.message.content'), true);
            $ideias = array_values(array_filter((array) ($dados['ideias'] ?? []),
                fn ($i) => is_array($i) && is_string($i['titulo'] ?? null) && trim($i['titulo']) !== ''));
        }
        if (! $ideias) {
            throw new RuntimeException('A IA não devolveu ideias válidas.');
        }

        // Datas: dentro do período, em dia útil que não seja feriado, às horas certas; se não, o próximo dia livre.
        $feriado = fn (Carbon $d) => str_starts_with($datas[$d->format('Y-m-d')] ?? '', 'Feriado');
        $livres = [];
        for ($d = $inicio->copy()->startOfDay(); $d->lte($fim); $d->addDay()) {
            if (! $d->isWeekend() && ! $feriado($d) && ! in_array($d->format('Y-m-d'), $ocupados, true)) {
                $livres[] = $d->format('Y-m-d');
            }
        }
        $resultado = [];
        $usados = $ocupados;
        foreach (array_slice($ideias, 0, $quantas) as $i => $ideia) {
            try {
                $quando = Carbon::parse((string) ($ideia['quando'] ?? ''));
            } catch (\Throwable) {
                $quando = null;
            }
            $hora = $quando && $quando->hour >= 8 && $quando->hour <= 21 ? $quando->format('H:i') : '10:00';
            if ($pedido) {
                $quando = $inicio->copy(); // trocar tema: a data da ideia não muda
            } elseif (! $quando || $quando->lt($inicio->copy()->startOfDay()) || $quando->gt($fim->copy()->endOfDay())
                || $quando->isWeekend() || $feriado($quando) || $quando->lt(now()->addHours(2))
                || (! $pedido && in_array($quando->format('Y-m-d'), $usados, true))) {
                $dia = collect($livres)->first(fn ($l) => ! in_array($l, $usados, true) && Carbon::parse("$l $hora")->gt(now()->addHours(2)));
                if (! $dia) {
                    continue; // sem dia livre neste período
                }
                $quando = Carbon::parse("$dia $hora");
            } else {
                $quando = Carbon::parse($quando->format('Y-m-d')." $hora");
            }
            $usados[] = $quando->format('Y-m-d');
            $tipo = in_array($ideia['tipo'] ?? '', \App\Console\Commands\CriarIdeia::TIPOS, true) ? $ideia['tipo'] : 'dica';

            $resultado[] = [
                'titulo' => mb_substr(trim($this->limparTexto($ideia['titulo'])), 0, 150),
                'tipo' => $tipo,
                'notas' => mb_substr(trim((string) $this->limparTexto(is_string($ideia['notas'] ?? null) ? $ideia['notas'] : '')), 0, 600),
                'quando' => $quando,
            ];
        }

        // Os temas aparecem no painel: também passam pelo revisor de português.
        $textos = [];
        foreach ($resultado as $i => $r) {
            $textos["$i.titulo"] = $r['titulo'];
            $textos["$i.notas"] = $r['notas'];
        }
        foreach ($this->revisorPt($textos) as $chave => $t) {
            [$i, $campo] = explode('.', $chave, 2);
            $resultado[(int) $i][$campo] = $t;
        }

        return $resultado;
    }

    /**
     * Chamada à IA. Se o plano gratuito pedir para esperar (429 com retry-after até 30 s),
     * espera e tenta outra vez, até 3 vezes, em vez de desistir.
     */
    private function chamar(int $timeout, array $pedido): \Illuminate\Http\Client\Response
    {
        for ($tentativa = 1; ; $tentativa++) {
            $resposta = Http::withToken(config('ia.chave'))->timeout($timeout)->acceptJson()
                ->post(rtrim(config('ia.endereco'), '/').'/chat/completions', ['model' => config('ia.modelo')] + $pedido);
            $espera = (float) $resposta->header('retry-after');
            if ($resposta->status() !== 429 || $tentativa >= 3 || $espera <= 0 || $espera > 30) {
                return $resposta;
            }
            usleep((int) (($espera + 0.5) * 1_000_000));
        }
    }

    /** O que cada botão da IA faz à legenda (editor). */
    public const ACOES_LEGENDA = [
        'escrever' => 'Escreve a legenda para este post, a partir do título e do texto da imagem.',
        'curta' => 'Reescreve a legenda mais curta (no máximo uns 400 caracteres), com a mesma mensagem, o convite ao contacto e as hashtags.',
        'profissional' => 'Reescreve a legenda com um tom mais profissional e institucional, sem perder a mensagem.',
        'proxima' => 'Reescreve a legenda com um tom mais próximo e simpático (sem exageros nem gíria), sem perder a mensagem.',
        'corrigir' => 'Corrige só erros de português de Portugal, acentos e pontuação. Não mudes mais nada.',
        'hashtags' => 'Mantém o texto exatamente igual e troca as hashtags do fim por 5 a 8 hashtags relevantes para o tema, incluindo #NEXUS.',
    ];

    /** A IA reescreve a legenda conforme o botão carregado no editor. Devolve a legenda nova. */
    public function legenda(string $acao, string $legenda, string $contexto): string
    {
        if (! $this->configurado()) {
            throw new RuntimeException('A IA não está configurada (IA_CHAVE no .env).');
        }
        $pedido = self::ACOES_LEGENDA[$acao] ?? throw new RuntimeException('Ação desconhecida.');

        $resposta = $this->chamar(60, [
                'messages' => [
                    ['role' => 'system', 'content' => "És o redator das redes sociais da NEXUS IT Solutions, empresa portuguesa de informática "
                        ."(servidores, workstations, redes, UPS, backup, cibersegurança). Site: nexus-solutions.pt, email: info@nexus-solutions.pt.\n"
                        ."Escreves SEMPRE em português de Portugal, nunca do Brasil (equipa, ecrã, utilizador, contacto, \"a fazer\").\n"
                        ."Nunca inventes preços, promoções, números ou características que não estejam no texto.\n"
                        ."Legendas: parágrafos curtos, no máximo 2200 caracteres, 1 ou 2 emojis no máximo, hashtags no fim.\n"
                        ."Usa hífens e espaços normais. Responde SÓ com a legenda final, sem aspas nem comentários."],
                    ['role' => 'user', 'content' => "Post: {$contexto}\n\nLegenda atual:\n".($legenda !== '' ? $legenda : '(vazia)')."\n\nPedido: {$pedido}"],
                ],
                'temperature' => $acao === 'corrigir' ? 0 : 0.7,
            ]);

        if ($resposta->failed()) {
            throw new RuntimeException('A IA respondeu com erro '.$resposta->status().'.');
        }
        $nova = trim((string) $this->limparTexto((string) $resposta->json('choices.0.message.content')), " \t\n\r\"");
        if ($nova === '') {
            throw new RuntimeException('A IA não devolveu nenhuma legenda.');
        }

        // Segunda passagem: revisor de português europeu.
        $nova = $this->revisorPt(['legenda' => $nova])['legenda'] ?? $nova;

        return mb_substr($nova, 0, 2200);
    }

    /** Passa uma pesquisa de fotos escrita em português para palavras-chave em inglês (os bancos de fotos funcionam melhor). */
    public function pesquisaEmIngles(string $pesquisa): string
    {
        $resposta = $this->chamar(20, [
                'messages' => [
                    ['role' => 'system', 'content' => 'Traduz o pedido para 2 a 5 palavras-chave em inglês, para pesquisar fotografias num banco de imagens. Responde só com as palavras, sem aspas nem pontuação.'],
                    ['role' => 'user', 'content' => mb_substr($pesquisa, 0, 200)],
                ],
                'temperature' => 0,
            ]);
        $texto = trim((string) $resposta->json('choices.0.message.content'), " \t\n\r\"'.");

        return $resposta->successful() && $texto !== '' ? mb_substr($texto, 0, 80) : $pesquisa;
    }

    /**
     * @return array{titulo_interno: string, design: array, legenda: string, redes: array, publicar_em: ?Carbon}
     */
    private function pedir(string $mensagem): array
    {
        if (! $this->configurado()) {
            throw new RuntimeException('A IA não está configurada (IA_CHAVE no .env).');
        }

        $erro = null;
        for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
            $resposta = $this->chamar(config('ia.timeout'), [
                        'messages' => [
                        ['role' => 'system', 'content' => $this->instrucoes()],
                        ['role' => 'user', 'content' => $mensagem],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.7,
                ]);

            if ($resposta->failed()) {
                // 429 = limite do plano gratuito; o comando volta a tentar mais tarde.
                throw new RuntimeException('A IA respondeu com erro '.$resposta->status().': '
                    .mb_substr((string) ($resposta->json('error.message') ?? $resposta->body()), 0, 300));
            }

            $dados = json_decode((string) $resposta->json('choices.0.message.content'), true);
            if (is_array($dados) && ! empty($dados['titulo']) && ! empty($dados['legenda'])) {
                return $this->revisto($this->normalizar($dados));
            }
            $erro = 'A IA não devolveu um post válido.';
        }

        throw new RuntimeException($erro);
    }

    /**
     * Limpa o texto que vem da IA: "\n" escrito à letra passa a mudança de linha, e hífens e espaços
     * "especiais" (ex.: U+2011 em "e‑mail") passam a normais, para não estragar o site e o email.
     */
    private function limparTexto(mixed $v): mixed
    {
        if (is_array($v)) {
            return array_map(fn ($x) => $this->limparTexto($x), $v);
        }
        if (! is_string($v)) {
            return $v;
        }

        // **negrito** de markdown: o Instagram mostra os asteriscos, por isso saem.
        return self::ptpt(strtr(str_replace(['\r\n', '\n', '**'], ["\n", "\n", ''], $v), [
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{00AD}" => '',
            "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ', "\u{200B}" => '',
        ]));
    }

    /** Brasileirismos que se corrigem sempre (palavra inteira; a maiúscula inicial mantém-se). */
    public const PT_BR_PT = [
        'detectar' => 'detetar', 'detecta' => 'deteta', 'detecte' => 'detete', 'detectam' => 'detetam',
        'detectado' => 'detetado', 'detectada' => 'detetada', 'detectados' => 'detetados', 'detectadas' => 'detetadas',
        'detecção' => 'deteção', 'detecções' => 'deteções',
        'equipe' => 'equipa', 'equipes' => 'equipas',
        'usuário' => 'utilizador', 'usuários' => 'utilizadores', 'usuária' => 'utilizadora',
        'tela' => 'ecrã', 'telas' => 'ecrãs', 'celular' => 'telemóvel', 'celulares' => 'telemóveis',
        'arquivo' => 'ficheiro', 'arquivos' => 'ficheiros',
        'contato' => 'contacto', 'contatos' => 'contactos', 'contate' => 'contacte', 'contate-nos' => 'contacte-nos',
        'registro' => 'registo', 'registros' => 'registos', 'cadastro' => 'registo', 'cadastre-se' => 'registe-se',
        'aplicativo' => 'aplicação', 'aplicativos' => 'aplicações',
        'gerenciar' => 'gerir', 'gerencie' => 'gira', 'gerenciamento' => 'gestão',
        'treinamento' => 'formação', 'treinamentos' => 'formações',
        'planilha' => 'folha de cálculo', 'planilhas' => 'folhas de cálculo',
        'ônibus' => 'autocarro', 'trem' => 'comboio', 'xícara' => 'chávena',
        'esporte' => 'desporto', 'salvar' => 'guardar', 'salve' => 'guarde', 'salvem' => 'guardem',
    ];

    /** Expressões em que a palavra muda de género ("na tela" → "no ecrã"); aplicam-se antes das palavras soltas. */
    public const PT_BR_PT_EXPRESSOES = [
        'na tela' => 'no ecrã', 'da tela' => 'do ecrã', 'a tela' => 'o ecrã', 'uma tela' => 'um ecrã', 'pela tela' => 'pelo ecrã',
        'nas telas' => 'nos ecrãs', 'das telas' => 'dos ecrãs', 'as telas' => 'os ecrãs',
        'o aplicativo' => 'a aplicação', 'do aplicativo' => 'da aplicação', 'no aplicativo' => 'na aplicação',
        'um aplicativo' => 'uma aplicação', 'pelo aplicativo' => 'pela aplicação', 'os aplicativos' => 'as aplicações',
        'dos aplicativos' => 'das aplicações', 'nos aplicativos' => 'nas aplicações',
        'o treinamento' => 'a formação', 'do treinamento' => 'da formação', 'no treinamento' => 'na formação',
        'um treinamento' => 'uma formação', 'o gerenciamento' => 'a gestão', 'do gerenciamento' => 'da gestão',
        'no gerenciamento' => 'na gestão', 'um gerenciamento' => 'uma gestão',
    ];

    /**
     * Rede de segurança para o português de Portugal: troca os brasileirismos mais comuns
     * e os acentos fechados do Brasil (eletrônico → eletrónico, gênero → género).
     */
    public static function ptpt(string $texto): string
    {
        foreach (self::PT_BR_PT_EXPRESSOES as $br => $pt) {
            $texto = preg_replace_callback('/(?<![\p{L}#@])'.preg_quote($br, '/').'(?![\p{L}])/iu', function ($m) use ($pt) {
                return mb_substr($m[0], 0, 1) !== mb_strtolower(mb_substr($m[0], 0, 1))
                    ? mb_strtoupper(mb_substr($pt, 0, 1)).mb_substr($pt, 1)
                    : $pt;
            }, $texto);
        }
        $texto = preg_replace_callback('/(?<![\p{L}#@])(\p{L}[\p{L}-]*)(?![\p{L}])/u', function ($m) {
            $palavra = $m[1];
            $minuscula = mb_strtolower($palavra);
            if (! isset(self::PT_BR_PT[$minuscula])) {
                return $palavra;
            }
            $nova = self::PT_BR_PT[$minuscula];
            if ($palavra === mb_strtoupper($palavra) && mb_strlen($palavra) > 1) {
                return mb_strtoupper($nova);
            }

            return mb_substr($palavra, 0, 1) !== mb_substr($minuscula, 0, 1)
                ? mb_strtoupper(mb_substr($nova, 0, 1)).mb_substr($nova, 1)
                : $nova;
        }, $texto);

        // ô/ê antes de m/n + vogal: eletrônico, econômico, gênero, acadêmico (não toca em "têm", "vêm").
        return preg_replace(['/ô(?=[mn][aeiouáéíóú])/u', '/ê(?=[mn][aeiouáéíóú])/u', '/Ô(?=[MN][AEIOU])/u', '/Ê(?=[MN][AEIOU])/u'],
            ['ó', 'é', 'Ó', 'É'], $texto);
    }

    /**
     * Revisor de português europeu: uma segunda passagem da IA que só corrige brasileirismos,
     * ortografia e pontuação, sem mudar o conteúdo. Se falhar, ficam os textos originais.
     *
     * @param  array<string, string>  $textos
     * @return array<string, string>
     */
    private function revisorPt(array $textos): array
    {
        $textos = array_filter($textos, fn ($t) => is_string($t) && trim($t) !== '');
        if (! $textos) {
            return [];
        }
        $revistos = null;
        for ($tentativa = 1; $tentativa <= 2 && ! is_array($revistos); $tentativa++) {
            $resposta = $this->chamar(60, [
                        'messages' => [
                        ['role' => 'system', 'content' => 'És revisor de português europeu (de Portugal) de uma empresa portuguesa. '
                            .'Recebes um objeto JSON com textos. Devolve o MESMO objeto JSON, com as mesmas chaves, corrigindo só: '
                            .'brasileirismos de vocabulário (equipe→equipa, tela→ecrã, usuário→utilizador, arquivo→ficheiro, contato→contacto, '
                            .'celular→telemóvel, time→equipa), de ortografia (detectar→detetar, fato→facto, econômico→económico) e de construção '
                            .'(gerúndio "estou fazendo"→"estou a fazer", "segue sendo"/"segue como"→"continua a ser", "você pode estar"→"pode estar"), '
                            .'mistura de "tu" e "você" (usa sempre "você"/"a sua empresa", sem "tu", "teu", "fica atento"; nas ordens e conselhos '
                            .'usa o imperativo de "você": Garanta, Proteja, Faça, Descubra, Fale, Saiba, e não Garante, Protege, Faz, Descobre, Fala, Sabe), '
                            .'e erros de ortografia, acentuação e pontuação. '
                            .'NÃO acrescentes nem tires informação, não mudes o sentido, o tom, os emojis, as mudanças de linha, os asteriscos, '
                            .'os endereços nem as hashtags (nas hashtags só corriges a grafia). Se um texto já estiver certo, devolve-o igual.'],
                        ['role' => 'user', 'content' => json_encode($textos, JSON_UNESCAPED_UNICODE)],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0,
                ]);
            if ($resposta->failed()) {
                // Sem revisão o texto não se usa (o pedido volta à fila / o botão avisa).
                throw new RuntimeException('O revisor de português não conseguiu rever o texto (a IA respondeu com erro '.$resposta->status().'). Tente daqui a um minuto.');
            }
            $revistos = json_decode((string) $resposta->json('choices.0.message.content'), true);
        }
        if (! is_array($revistos)) {
            throw new RuntimeException('O revisor de português não devolveu uma revisão válida. Tente outra vez.');
        }
        // Só aceita a revisão de cada texto se veio como texto e não ficou muito diferente em tamanho.
        foreach ($textos as $chave => $original) {
            $novo = $revistos[$chave] ?? null;
            if (is_string($novo) && trim($novo) !== '' && mb_strlen($novo) <= mb_strlen($original) * 1.3 + 20) {
                $textos[$chave] = $this->limparTexto($novo);
            }
        }

        return $textos;
    }

    /** Passa pelo revisor todos os textos que vão ser publicados (legenda e textos da imagem). */
    private function revisto(array $r): array
    {
        $textos = ['legenda' => $r['legenda'], 'titulo_interno' => $r['titulo_interno']];
        foreach (['titulo', 'texto', 'cta', 'rodape', 'etiqueta', 'data', 'hora', 'fecho'] as $c) {
            if (isset($r['design'][$c]) && is_string($r['design'][$c])) {
                $textos['design.'.$c] = $r['design'][$c];
            }
        }
        foreach ((array) ($r['design']['pontos'] ?? []) as $i => $p) {
            if (is_array($p)) {
                $textos["ponto.$i.texto"] = (string) ($p['texto'] ?? '');
                $textos["ponto.$i.destaque"] = (string) ($p['destaque'] ?? '');
            } elseif (is_string($p)) {
                $textos["ponto.$i"] = $p;
            }
        }
        if (! empty($r['design']['evento']['local'])) {
            $textos['evento.local'] = $r['design']['evento']['local'];
        }
        foreach ((array) ($r['design']['slides'] ?? []) as $i => $sl) {
            foreach (['destaque', 'titulo', 'texto'] as $c) {
                if (is_array($sl) && is_string($sl[$c] ?? null)) {
                    $textos["slide.$i.$c"] = $sl[$c];
                }
            }
        }

        foreach ($this->revisorPt($textos) as $chave => $t) {
            if ($chave === 'legenda' || $chave === 'titulo_interno') {
                $r[$chave] = $t;
            } elseif (str_starts_with($chave, 'design.')) {
                $r['design'][substr($chave, 7)] = $t;
            } elseif (preg_match('/^ponto\.(\d+)\.(texto|destaque)$/', $chave, $m)) {
                $r['design']['pontos'][(int) $m[1]][$m[2]] = $t;
            } elseif (preg_match('/^ponto\.(\d+)$/', $chave, $m)) {
                $r['design']['pontos'][(int) $m[1]] = $t;
            } elseif (preg_match('/^slide\.(\d+)\.(destaque|titulo|texto)$/', $chave, $m)) {
                $r['design']['slides'][(int) $m[1]][$m[2]] = $t;
            } elseif ($chave === 'evento.local') {
                $r['design']['evento']['local'] = $t;
            }
        }

        return $r;
    }

    private function normalizar(array $d): array
    {
        $d = $this->limparTexto($d);
        $design = array_intersect_key($d, array_flip([
            'modelo', 'formato', 'numero', 'titulo', 'texto', 'etiqueta', 'rodape', 'icone', 'cta', 'pontos', 'data', 'hora', 'evento',
            'carrossel', 'slides', 'fecho',
        ]));
        // Carrossel: só com slides e no feed (o limparDesign do PostRenderer confirma o resto).
        $design['carrossel'] = ! empty($design['carrossel']) && ! empty($design['slides']) && is_array($design['slides'])
            && in_array($design['modelo'] ?? '', ['dica', 'numero', 'produto', 'frase', 'montra'], true);
        if (! $design['carrossel']) {
            unset($design['carrossel'], $design['slides'], $design['fecho']);
        } else {
            $design['formato'] = ($design['formato'] ?? '') === '1:1' ? '1:1' : '4:5';
            if (($design['modelo'] ?? '') === 'dica') {
                unset($design['pontos']); // os pontos estão nos slides; a capa fica limpa
            }
        }
        $produtoBib = (int) ($d['produto_biblioteca'] ?? 0);
        $permitidos = ['dica', 'produto', 'aviso', 'evento', 'numero', 'frase'];
        if ($produtoBib > 0) {
            $permitidos[] = 'montra'; // só com a foto oficial do produto, da biblioteca
        }
        if (! in_array($design['modelo'] ?? '', $permitidos, true)) {
            $design['modelo'] = 'dica'; // "montra" precisa da foto oficial do produto, que a IA não tem
        }
        if (($design['etiqueta'] ?? null) === '') {
            unset($design['etiqueta']); // vazio = a etiqueta do tipo de post
        }

        $redes = array_values(array_intersect(Post::NETWORKS, (array) ($d['redes'] ?? [])));

        $quando = null;
        if (! empty($d['publicar_em'])) {
            try {
                $quando = Carbon::parse($d['publicar_em']);
            } catch (\Throwable) {
                $quando = null;
            }
        }
        if (! $quando || $quando->lt(now()->addHour())) {
            $quando = $this->proximoDiaUtil();
        }

        return [
            'titulo_interno' => mb_substr(trim((string) ($d['titulo_interno'] ?? '')), 0, 150)
                ?: mb_substr(trim(str_replace(['*', "\n"], ['', ' '], $design['titulo'])), 0, 150),
            'design' => $design,
            'legenda' => mb_substr(trim((string) $d['legenda']), 0, 2200),
            'redes' => $redes ?: ['Instagram', 'Facebook'],
            'publicar_em' => $quando,
            'pesquisa_imagem' => mb_substr(trim((string) ($d['pesquisa_imagem'] ?? '')), 0, 80),
            'produto_biblioteca' => $produtoBib,
            'parceiro_biblioteca' => (int) ($d['parceiro_biblioteca'] ?? 0),
        ];
    }

    private function proximoDiaUtil(): Carbon
    {
        $d = now()->addDay()->setTime(10, 0);
        while ($d->isWeekend()) {
            $d->addDay();
        }

        return $d;
    }

    private function instrucoes(): string
    {
        $hoje = now()->locale('pt_PT')->translatedFormat('l, j \d\e F \d\e Y, H:i');
        $icones = implode(', ', PostRenderer::ICONES);
        $empresa = self::EMPRESA;

        return <<<TXT
        És o redator das redes sociais (Instagram, Facebook e LinkedIn) da NEXUS IT Solutions.
        Hoje é {$hoje} (hora de Portugal).

        A EMPRESA
        {$empresa}

        REGRAS
        - Ortografia de Portugal: detetar, proteção, ação, ótimo, contacto, facto (nunca detectar, detecte, fato).
        - Escreve SEMPRE em português de Portugal, nunca do Brasil (equipa, ecrã, telemóvel, utilizador, contacto,
          registo, ficheiro, "a fazer" e não "fazendo"; trata o leitor por "você"/"a sua empresa").
        - Nunca inventes preços, promoções, características técnicas, datas, números de clientes ou certificações
          que não estejam no pedido. Na dúvida, fala da solução em geral.
        - O texto da imagem é curto. A explicação vai na legenda.

        A IMAGEM (o servidor monta-a com um destes modelos; tu só escreves os textos)
        - "dica": conselho útil. Pode ter "pontos" (2 a 4). Se cada ponto tiver um número ou destaque curto
          (ex.: "1", "24h", "99,9%"), mete-o em "destaque" e aparece como cartão.
          Se o título disser um número ("4 sinais", "3 passos"), tem de haver EXATAMENTE esse número de pontos.
        - "produto": produto ou serviço. "pontos" = 2 a 4 vantagens curtas (deixa "destaque" vazio). Usa "cta".
        - "aviso": fecho, feriado, horário, mudança. Usa "data" (ex.: "Sexta-feira, 8 de dezembro") e "hora" se fizer sentido.
        - "evento": workshop, feira, webinar. Preenche "evento" (dia, mês abreviado, hora, local). Usa "cta".
        - "numero": quando o tema é uma lista com um número ("5 sinais de que…", "3 erros a evitar…"). Põe o número em "numero"
          (só o algarismo, ex.: "5") e o resto do título em "titulo", SEM o número, em 2 a 4 linhas curtas. Sem "pontos".
          "texto" = uma frase curta por baixo. Os pontos explicam-se na legenda.
        - "frase": mensagem institucional curta e forte (valores, slogan, frase de marca). "titulo" = a frase (máx. ~40 caracteres,
          2 a 3 linhas); "texto" = um complemento curto (máx. ~40 caracteres). Sem pontos nem ícone.
        - "montra": produto em destaque com a foto oficial. SÓ quando o pedido traz a lista de imagens oficiais da NEXUS
          e um produto dessa lista corresponde ao tema. "titulo" = nome do produto (curto); "texto" = uma frase curta.
        - Varia os modelos: não uses sempre "dica".
        - "titulo": 2 a 3 linhas curtas separadas por \\n, no máximo ~22 caracteres por linha.
          Põe UMA ou duas palavras importantes entre asteriscos para ficarem a verde, ex.: "O seu backup está\\nmesmo *seguro*?".
        - "texto": uma frase curta (máx. ~90 caracteres) por baixo do título.
        - "pontos": cada texto com no máximo ~35 caracteres. O "destaque" também em português (ex.: "Lítio", nunca "Lithium").
        - Todo o texto da imagem em português de Portugal; só "pesquisa_imagem" é em inglês.
        - "icone": um destes: {$icones} (ou "" para nenhum). Escolhe o que combina com o tema.
        - "rodape": tema do post em 2 a 4 palavras (ex.: "Backup & Disaster Recovery").
        - "etiqueta": deixa "" (usa a do tipo de post), a não ser que outra palavra seja claramente melhor.
        - "formato": "4:5" (normal) ou "1:1" (avisos curtos).
        - Sem emojis no texto da imagem.
        - "pesquisa_imagem": 2 a 4 palavras EM INGLÊS para procurar uma foto de fundo num banco de fotos
          (ex.: "server room", "it technician laptop", "office team meeting", "data center cables").
          Foto realista, sem texto, a combinar com o tema. NÃO uses siglas nem marcas (UPS, NVIDIA, Riello, Supermicro):
          descreve o que se vê (ex.: UPS → "office computers power backup", NVIDIA → "graphics card computer"). Num aviso de fecho ou feriado pode ser algo como "closed office door".

        CARROSSEL (várias imagens no mesmo post: capa + um slide por ponto + slide de contactos, que é automático)
        - Usa "carrossel": true quando o tema é um guia, passos, sinais, erros ou dicas em que cada ponto merece
          1 ou 2 frases de explicação (ex.: "4 sinais de phishing", "5 passos para um backup seguro"). Prende mais a atenção
          do que uma imagem só. Nos outros casos (avisos, eventos, produtos simples, frases) usa false.
        - A capa é o modelo escolhido, normalmente "numero" (ex.: numero "4", titulo "sinais de um\\n*email* de\\nphishing")
          ou "dica" SEM "pontos". Em carrossel, deixa "pontos" vazio: os pontos vão para "slides".
        - "slides": 3 a 6, um por ponto. Cada um: "destaque" (o número do ponto "1", "2"… ou algo curto como "24h", "3-2-1"),
          "titulo" (2 linhas curtas separadas por \\n, máx. ~20 caracteres por linha, 1 palavra entre *asteriscos*),
          "texto" (1 ou 2 frases, máx. ~170 caracteres, que explicam o ponto).
        - "fecho": título do último slide (contactos), ex.: "Fale com a\\n*NEXUS*" ou "Proteja a\\nsua *empresa*". Curto.
        - Se o título da capa disser um número, tem de haver EXATAMENTE esse número de slides.

        A LEGENDA
        - 300 a 900 caracteres, em parágrafos curtos, a explicar o tema e porque interessa a uma empresa.
          Se a imagem tem pontos (sinais, passos, vantagens), a legenda desenvolve cada um numa linha.
        - Usa hífens e espaços normais (nada de caracteres especiais em "e-mail", no site ou no email).
        - Termina com um convite ao contacto (nexus-solutions.pt ou info@nexus-solutions.pt) e 4 a 8 hashtags,
          incluindo #NEXUS. Pode ter 1 ou 2 emojis, no máximo.

        PUBLICAÇÃO
        - "redes": ["Instagram","Facebook"]; junta "LinkedIn" quando o tema é técnico ou para empresas.
        - "publicar_em": "AAAA-MM-DD HH:MM". Se o pedido indicar uma data, usa-a (um aviso de fecho publica-se na véspera).
          Se não, escolhe o próximo dia útil às 10:00.

        RESPONDE SÓ com um objeto JSON, sem mais nada, exatamente com estes campos:
        {
          "titulo_interno": "nome curto do post para o painel",
          "modelo": "dica|produto|aviso|evento|numero|frase|montra",
          "numero": "",
          "formato": "4:5|1:1",
          "titulo": "...",
          "texto": "...",
          "etiqueta": "",
          "rodape": "...",
          "icone": "...",
          "cta": "",
          "pontos": [{"destaque": "", "texto": "..."}],
          "data": "",
          "hora": "",
          "evento": {"dia": "", "mes": "", "hora": "", "local": ""},
          "carrossel": false,
          "slides": [{"destaque": "1", "titulo": "...", "texto": "..."}],
          "fecho": "",
          "legenda": "...",
          "redes": ["Instagram", "Facebook"],
          "publicar_em": "AAAA-MM-DD HH:MM",
          "pesquisa_imagem": "...",
          "produto_biblioteca": 0,
          "parceiro_biblioteca": 0
        }
        Campos que não se aplicam ao modelo escolhido ficam vazios ("" ou []).
        TXT;
    }
}
