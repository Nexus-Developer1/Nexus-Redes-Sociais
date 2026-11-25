<?php

namespace App\Services\Ia;

use App\Models\Post;
use App\Support\AvisoDatas;
use App\Support\PortuguesPt;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Escreve posts com a IA: a partir de um tema ou de um pedido de alteração devolve os textos do modelo,
 * a legenda, as redes e a data. A imagem é sempre montada depois pelo PostRenderer, nunca gerada pela IA.
 * As instruções estão em InstrucoesIa; todos os textos passam pelo revisor de português (ClienteIa::rever).
 */
class PostWriter
{
    public function __construct(private ClienteIa $ia) {}

    /**
     * Carrossel ou não: decide sempre quem pede, nunca a IA.
     * 'sim' = tem de ser carrossel; 'nao' = imagem única; 'livre' = alteração a um carrossel (pode mantê-lo ou tirá-lo).
     */
    private string $carrossel = 'nao';

    /** Post novo a partir do tema escrito em "Pedir um post" (a data de publicação e o formato, se escolhidos). */
    public function criar(string $tema, ?Carbon $publicarEm = null, bool $carrossel = false): array
    {
        $data = $publicarEm
            ? "\nA data de publicação já está decidida: ".$publicarEm->locale('pt_PT')->translatedFormat('l, j \d\e F \d\e Y, H:i')
              .'. Usa-a em "publicar_em" e não a mudes. (A data do acontecimento, se houver, é a que vem no tema.)'
            : '';
        $this->carrossel = $carrossel ? 'sim' : 'nao';
        $formato = $carrossel
            ? "\nFORMATO: carrossel (obrigatório). \"carrossel\": true, com 3 a 6 \"slides\", um por ponto."
            : "\nFORMATO: imagem única. \"carrossel\": false e \"slides\": [].";

        $r = $this->pedir("Pedido de post: \"{$tema}\"{$data}{$formato}\n".InstrucoesIa::catalogo()."\nCria o post completo.");
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

        // Só passa a carrossel se a alteração o pedir; um carrossel mantém-se (ou sai, se a alteração o pedir).
        $eraCarrossel = ! empty($post->design['carrossel']);
        $pedeCarrossel = str_contains(mb_strtolower($alteracao), 'carross');
        $this->carrossel = $eraCarrossel ? 'livre' : ($pedeCarrossel ? 'sim' : 'nao');
        $formato = match ($this->carrossel) {
            'sim' => "\nFORMATO: a alteração pede carrossel: \"carrossel\": true, com 3 a 6 \"slides\".",
            'nao' => "\nFORMATO: imagem única. \"carrossel\": false e \"slides\": [].",
            default => "\nFORMATO: é um carrossel. Mantém-no, a não ser que a alteração peça uma imagem única.",
        };

        return $this->pedir(
            "Post atual:\n{$atual}\n\nO aprovador pediu esta alteração: \"{$alteracao}\"\n{$formato}\n\n"
            .'Devolve o post completo com a alteração feita. Mantém tudo o resto igual, a não ser que a alteração o obrigue a mudar.'
        );
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
        $this->ia->exigirConfigurado();
        $pedido = self::ACOES_LEGENDA[$acao] ?? throw new RuntimeException('Ação desconhecida.');

        $resposta = $this->ia->chamar(60, [
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
        $nova = trim((string) PortuguesPt::limpar(ClienteIa::texto($resposta)), " \t\n\r\"");
        if ($nova === '') {
            throw new RuntimeException('A IA não devolveu nenhuma legenda.');
        }

        // Segunda passagem: revisor de português europeu.
        $nova = $this->ia->rever(['legenda' => $nova])['legenda'] ?? $nova;

        return mb_substr($nova, 0, 2200);
    }

    /**
     * @return array{titulo_interno: string, design: array, legenda: string, redes: array, publicar_em: ?Carbon}
     */
    private function pedir(string $mensagem): array
    {
        $this->ia->exigirConfigurado();

        $erro = null;
        for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
            $resposta = $this->ia->chamar(config('ia.timeout'), [
                        'messages' => [
                        ['role' => 'system', 'content' => InstrucoesIa::post()],
                        ['role' => 'user', 'content' => $mensagem],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.7,
                ]);

            if ($resposta->failed()) {
                // 429 = limite do plano gratuito; o comando volta a tentar mais tarde.
                throw ClienteIa::erro($resposta);
            }

            $dados = json_decode(ClienteIa::texto($resposta), true);
            if (is_array($dados) && ! empty($dados['titulo']) && ! empty($dados['legenda'])) {
                return $this->revisto($this->normalizar($dados));
            }
            $erro = 'A IA não devolveu um post válido.';
        }

        throw new RuntimeException($erro);
    }

    /** Passa pelo revisor todos os textos que vão ser publicados (legenda e textos da imagem). */
    private function revisto(array $r): array
    {
        $textos = ['legenda' => $r['legenda'], 'titulo_interno' => $r['titulo_interno']];
        foreach (['titulo', 'texto', 'cta', 'rodape', 'data', 'hora', 'fecho'] as $c) {
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

        $referencia = $r['publicar_em'] instanceof Carbon ? $r['publicar_em'] : now();
        foreach ($this->ia->rever($textos) as $chave => $t) {
            // a IA engana-se no dia da semana ("Sábado, 30 de novembro" num ano em que é segunda): acerta-se aqui
            $t = \App\Support\AvisoDatas::acertarDiasDaSemana($t, $referencia);
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
        $d = PortuguesPt::limpar($d);
        $design = array_intersect_key($d, array_flip([
            'modelo', 'formato', 'numero', 'titulo', 'texto', 'rodape', 'icone', 'cta', 'pontos', 'data', 'hora', 'evento',
            'carrossel', 'slides', 'fecho',
        ]));
        // Carrossel: decide quem pediu, nunca a IA (ver $this->carrossel). Só no feed; o limparDesign confirma o resto.
        $querCarrossel = match ($this->carrossel) {
            'sim' => true,
            'nao' => false,
            default => ! empty($design['carrossel']),
        };
        $slides = $querCarrossel && is_array($design['slides'] ?? null) ? array_values(array_filter($design['slides'], 'is_array')) : [];
        if ($querCarrossel && ! $slides) {
            // Pediram carrossel mas a IA não fez os slides: cada ponto da capa passa a um slide.
            foreach (array_values((array) ($design['pontos'] ?? [])) as $i => $p) {
                $texto = trim(is_array($p) ? (string) ($p['texto'] ?? '') : (string) $p);
                if ($texto !== '') {
                    $slides[] = ['destaque' => is_array($p) && ! empty($p['destaque']) ? $p['destaque'] : (string) ($i + 1), 'titulo' => $texto];
                }
            }
        }
        if ($querCarrossel && $slides) {
            $design['carrossel'] = true;
            $design['slides'] = $slides;
            if (! in_array($design['modelo'] ?? '', ['dica', 'numero', 'produto', 'frase', 'montra'], true)) {
                $design['modelo'] = 'dica'; // aviso e evento não têm carrossel
            }
            if (($design['modelo'] ?? '') === 'dica') {
                unset($design['pontos']); // os pontos estão nos slides; a capa fica limpa
            }
        } else {
            unset($design['carrossel'], $design['slides'], $design['fecho']);
        }
        $design['formato'] = '4:5'; // padrão do feed da NEXUS (24 nov): a IA já não faz posts 1:1
        $produtoBib = (int) ($d['produto_biblioteca'] ?? 0);
        $permitidos = ['dica', 'produto', 'aviso', 'evento', 'numero', 'frase'];
        if ($produtoBib > 0) {
            $permitidos[] = 'montra'; // só com a foto oficial do produto, da biblioteca
        }
        if (! in_array($design['modelo'] ?? '', $permitidos, true)) {
            $design['modelo'] = 'dica'; // "montra" precisa da foto oficial do produto, que a IA não tem
        }

        // Vertente das redes sociais: sempre Instagram, Facebook e LinkedIn (a IA não tira nenhuma; os Stories juntam-se depois).
        $redes = array_values(array_intersect(Post::NETWORKS, array_merge(Post::REDES_SEMPRE, (array) ($d['redes'] ?? []))));

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
            'redes' => $redes,
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
}
