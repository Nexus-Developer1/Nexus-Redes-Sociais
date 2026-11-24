<?php

namespace App\Services;

use App\Models\NoticiaIa;
use App\Models\ResumoIa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * O "agente" das notícias de IA: recolhe as notícias dos feeds (config ia.fontes_noticias) e pede à IA o resumo.
 * O texto dos feeds vem de fora: guarda-se só como texto (sem HTML) e nunca entra como instrução para a IA.
 */
class NoticiasIa
{
    /** Notícias mais antigas do que isto não entram (alguns feeds trazem anos de arquivo). */
    public const DIAS = 10;

    /** No máximo, por fonte e por recolha (as mais recentes). */
    public const POR_FONTE = 15;

    /** No máximo, num resumo (as mais recentes): a IA gratuita tem pouco espaço. */
    public const NO_RESUMO = 45;

    /** Parceiros: publicam menos (a Riello e a Open-E uma vez por mês), por isso entram notícias até 30 dias. */
    public const DIAS_PARCEIROS = 30;

    public const POR_PARCEIRO = 6;

    /**
     * Lê todos os feeds e guarda as notícias novas. Uma fonte que falhe não impede as outras.
     *
     * @return array{novas: int, erros: array<string, string>}
     */
    public function recolher(): array
    {
        $novas = 0;
        $erros = [];
        // [lista, é parceiro, dias, no máximo por fonte]: os parceiros publicam menos, por isso vai-se mais para trás
        $listas = [
            [(array) config('ia.fontes_noticias'), false, self::DIAS, self::POR_FONTE],
            [(array) config('ia.fontes_parceiros'), true, self::DIAS_PARCEIROS, self::POR_PARCEIRO],
        ];
        foreach ($listas as [$fontes, $parceiro, $dias, $maximo]) {
            foreach ($fontes as $fonte => $url) {
                try {
                    $resposta = Http::timeout(25)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; NEXUS-noticias)'])->get($url);
                    if ($resposta->failed()) {
                        throw new RuntimeException('erro '.$resposta->status());
                    }
                    foreach (array_slice(self::lerFeed($resposta->body(), $dias), 0, $maximo) as $n) {
                        $nova = NoticiaIa::firstOrCreate(['link' => $n['link']], ['fonte' => $fonte, 'parceiro' => $parceiro] + $n);
                        $novas += $nova->wasRecentlyCreated ? 1 : 0;
                    }
                } catch (Throwable $e) {
                    $erros[$fonte] = mb_substr($e->getMessage(), 0, 200);
                }
            }
        }

        return ['novas' => $novas, 'erros' => $erros];
    }

    /**
     * Lê um feed RSS 2.0 ou Atom. Devolve as notícias dos últimos DIAS, das mais recentes para as mais antigas,
     * só com texto (as tags HTML saem).
     *
     * @return list<array{titulo: string, link: string, descricao: ?string, publicada_em: ?Carbon}>
     */
    public static function lerFeed(string $xml, int $dias = self::DIAS): array
    {
        $anterior = libxml_use_internal_errors(true);
        // LIBXML_NONET: o feed não pode mandar o servidor buscar entidades externas
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_use_internal_errors($anterior);
        if (! $doc) {
            throw new RuntimeException('o feed não é XML válido');
        }

        $itens = [];
        if (isset($doc->channel->item)) { // RSS 2.0
            foreach ($doc->channel->item as $i) {
                $itens[] = [(string) $i->title, (string) $i->link, (string) ($i->description ?? ''), (string) ($i->pubDate ?? ''), trim((string) ($i->source ?? ''))];
            }
        } else { // Atom
            foreach ($doc->entry ?? [] as $e) {
                $link = '';
                foreach ($e->link as $l) {
                    if (in_array((string) ($l['rel'] ?? 'alternate'), ['alternate', ''], true)) {
                        $link = (string) $l['href'];
                        break;
                    }
                }
                $itens[] = [(string) $e->title, $link, (string) ($e->summary ?? $e->content ?? ''), (string) ($e->published ?? $e->updated ?? '')];
            }
        }

        $limpa = fn (string $t) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $desde = now()->subDays($dias);
        $lista = [];
        foreach ($itens as $item) {
            [$titulo, $link, $descricao, $data] = $item;
            $link = trim($link);
            if ($titulo === '' || ! preg_match('#^https?://#i', $link)) {
                continue;
            }
            // Google Notícias: o título acaba em " - <site>" (o <source> do item) e a descrição só repete o título
            if (preg_match('#^https://news\.google\.com/#i', $link)) {
                $site = $item[4] ?? '';
                if ($site !== '' && str_ends_with($titulo, ' - '.$site)) {
                    $titulo = mb_substr($titulo, 0, mb_strlen($titulo) - mb_strlen(' - '.$site));
                }
                $descricao = '';
                // páginas genéricas do site ("Newsroom", "Software Releases | Ubiquiti Community") não são notícias
                $titulo = preg_replace('/\s+\|\s+[^|]{2,60}$/u', '', $titulo) ?: $titulo;
                if (count(preg_split('/\s+/u', trim($titulo))) < 4) {
                    continue;
                }
            }
            $quando = null;
            try {
                $quando = $data !== '' ? Carbon::parse($data) : null;
            } catch (Throwable) {
            }
            if ($quando && $quando->lt($desde)) {
                continue;
            }
            $lista[] = [
                'titulo' => mb_substr($limpa($titulo), 0, 300),
                'link' => mb_substr($link, 0, 700),
                'descricao' => mb_substr($limpa($descricao), 0, 600) ?: null,
                'publicada_em' => $quando?->setTimezone(config('app.timezone')),
            ];
        }
        usort($lista, fn ($a, $b) => ($b['publicada_em']?->timestamp ?? 0) <=> ($a['publicada_em']?->timestamp ?? 0));

        return $lista;
    }

    /**
     * Resumo das notícias recolhidas desde o último resumo (ou dos últimos 4 dias). Null se não houver notícias.
     *
     * @throws RuntimeException se a IA falhar
     */
    public function resumir(PostWriter $escritor): ?ResumoIa
    {
        $ultimo = ResumoIa::latest('fim')->first();
        $inicio = $ultimo?->fim ?? now()->subDays(4);
        $fim = now();
        // Só as de IA: os parceiros têm o separador próprio (e a IA gratuita não tem espaço para mais 13 fontes)
        $noticias = NoticiaIa::deIa()->where(fn ($q) => $q->where('created_at', '>', $inicio)->orWhere('publicada_em', '>', $inicio))
            ->orderByDesc('publicada_em')->limit(self::NO_RESUMO)->get();
        if ($noticias->isEmpty()) {
            return null;
        }

        $conteudo = $escritor->resumirNoticias($noticias->map(fn ($n) => [
            'id' => $n->id, 'fonte' => $n->fonte, 'titulo' => $n->titulo, 'descricao' => (string) $n->descricao,
        ])->all(), $inicio, $fim);

        return ResumoIa::create([
            'inicio' => $inicio, 'fim' => $fim, 'conteudo' => $conteudo,
            'n_noticias' => $noticias->count(), 'modelo' => config('ia.modelo'),
        ]);
    }
}
