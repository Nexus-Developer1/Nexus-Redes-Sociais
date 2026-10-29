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
     * @return array{titulo_interno: string, design: array, legenda: string, redes: array, publicar_em: ?Carbon}
     */
    private function pedir(string $mensagem): array
    {
        if (! $this->configurado()) {
            throw new RuntimeException('A IA não está configurada (IA_CHAVE no .env).');
        }

        $erro = null;
        for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
            $resposta = Http::withToken(config('ia.chave'))
                ->timeout(config('ia.timeout'))
                ->acceptJson()
                ->post(rtrim(config('ia.endereco'), '/').'/chat/completions', [
                    'model' => config('ia.modelo'),
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
                return $this->normalizar($dados);
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

        return strtr(str_replace(['\r\n', '\n'], "\n", $v), [
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{00AD}" => '',
            "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ', "\u{200B}" => '',
        ]);
    }

    private function normalizar(array $d): array
    {
        $d = $this->limparTexto($d);
        $design = array_intersect_key($d, array_flip([
            'modelo', 'formato', 'titulo', 'texto', 'etiqueta', 'rodape', 'icone', 'cta', 'pontos', 'data', 'hora', 'evento',
        ]));
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
        - "pontos": cada texto com no máximo ~35 caracteres.
        - "icone": um destes: {$icones} (ou "" para nenhum). Escolhe o que combina com o tema.
        - "rodape": tema do post em 2 a 4 palavras (ex.: "Backup & Disaster Recovery").
        - "etiqueta": deixa "" (usa a do tipo de post), a não ser que outra palavra seja claramente melhor.
        - "formato": "4:5" (normal) ou "1:1" (avisos curtos).
        - Sem emojis no texto da imagem.

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
          "publicar_em": "AAAA-MM-DD HH:MM"
        }
        Campos que não se aplicam ao modelo escolhido ficam vazios ("" ou []).
        TXT;
    }
}
