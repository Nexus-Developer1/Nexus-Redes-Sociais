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

        $r = $this->pedir("Pedido de post: \"{$tema}\"{$data}\n\nCria o post completo.");
        if ($publicarEm) {
            $r['publicar_em'] = $publicarEm->copy(); // a escolha de quem pediu manda sempre
        }

        return $r;
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
                            .'mistura de "tu" e "você" (usa sempre "você"/"a sua empresa", sem "tu", "teu", "fica atento"), '
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
        foreach (['titulo', 'texto', 'cta', 'rodape', 'etiqueta', 'data', 'hora'] as $c) {
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

        foreach ($this->revisorPt($textos) as $chave => $t) {
            if ($chave === 'legenda' || $chave === 'titulo_interno') {
                $r[$chave] = $t;
            } elseif (str_starts_with($chave, 'design.')) {
                $r['design'][substr($chave, 7)] = $t;
            } elseif (preg_match('/^ponto\.(\d+)\.(texto|destaque)$/', $chave, $m)) {
                $r['design']['pontos'][(int) $m[1]][$m[2]] = $t;
            } elseif (preg_match('/^ponto\.(\d+)$/', $chave, $m)) {
                $r['design']['pontos'][(int) $m[1]] = $t;
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
            'modelo', 'formato', 'titulo', 'texto', 'etiqueta', 'rodape', 'icone', 'cta', 'pontos', 'data', 'hora', 'evento',
        ]));
        if (! in_array($design['modelo'] ?? '', ['dica', 'produto', 'aviso', 'evento'], true)) {
            $design['modelo'] = 'dica'; // o modelo "foto" precisa de uma foto, que a IA não tem
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

        return <<<TXT
        És o redator das redes sociais (Instagram, Facebook e LinkedIn) da NEXUS IT Solutions.
        Hoje é {$hoje} (hora de Portugal).

        A EMPRESA
        NEXUS Solutions / NEXUS IT Solutions: empresa portuguesa de tecnologias de informação, fundada em 2010.
        Site: nexus-solutions.pt. Contacto: info@nexus-solutions.pt, 229 437 980.
        Produtos e serviços: PCs, mini-PCs, POS, workstations (3D, CAD, render), servidores e storage, GPUs NVIDIA,
        Supermicro, alta disponibilidade, backup e disaster recovery, cibersegurança, videoconferência, datacenters,
        redes, UPS Riello, bastidores, consultoria e suporte, programa de revendedores.
        Clientes: sobretudo empresas (B2B). Tom: profissional, próximo e direto, sem exageros.

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
          "modelo": "dica|produto|aviso|evento",
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
          "legenda": "...",
          "redes": ["Instagram", "Facebook"],
          "publicar_em": "AAAA-MM-DD HH:MM",
          "pesquisa_imagem": "..."
        }
        Campos que não se aplicam ao modelo escolhido ficam vazios ("" ou []).
        TXT;
    }
}
