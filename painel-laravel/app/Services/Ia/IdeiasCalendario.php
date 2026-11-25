<?php

namespace App\Services\Ia;

use App\Support\PortuguesPt;
use Illuminate\Support\Carbon;
use RuntimeException;

/** A IA propõe os temas do calendário (ideias da semana e "Trocar tema"). */
class IdeiasCalendario
{
    /** Tipos de ideia que a IA pode propor ("Tipo: dica." na origem da ideia). */
    public const TIPOS = ['produto', 'dica', 'evento', 'aviso'];

    public function __construct(private ClienteIa $ia) {}

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
    public function propor(Carbon $inicio, Carbon $fim, int $quantas, array $datas, array $recentes, array $ocupados = [], ?string $pedido = null): array
    {
        $this->ia->exigirConfigurado();
        if ($quantas < 1) {
            return [];
        }

        $f = fn (Carbon $d) => $d->locale('pt_PT')->translatedFormat('l, j \d\e F \d\e Y');
        $lista = fn (array $l) => $l ? '- '.implode("\n- ", $l) : '(nenhum)';
        $especiais = collect($datas)->map(fn ($nome, $dia) => $f(Carbon::parse($dia)).": {$nome}")->values()->all();
        $tipos = implode(', ', self::TIPOS);
        $periodo = $inicio->isSameDay($fim) ? $f($inicio) : 'de '.$f($inicio).' a '.$f($fim);

        $mensagem = ($pedido
            ? "Quem aprova os posts pediu para trocar o tema do post de {$periodo}. O que pediu: \"{$pedido}\"\nPropõe 1 tema novo que responda a esse pedido."
            : "Propõe {$quantas} temas de posts para {$periodo}.")
            ."\n\nDIAS ESPECIAIS NESTE PERÍODO:\n".$lista($especiais)
            ."\n\nDIAS QUE JÁ TÊM POST (evita-os):\n".$lista(array_map(fn ($d) => $f(Carbon::parse($d)), $ocupados))
            ."\n\nTEMAS RECENTES OU JÁ MARCADOS (não repitas nem faças parecido):\n".$lista(array_slice($recentes, 0, 60))
            .InstrucoesIa::catalogo();

        $sistema = 'És o responsável pelo calendário das redes sociais (Instagram, Facebook e LinkedIn) da NEXUS IT Solutions. '
            .'Hoje é '.now()->locale('pt_PT')->translatedFormat('l, j \d\e F \d\e Y').".\n\nA EMPRESA\n".InstrucoesIa::EMPRESA."\n\n"
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
            $resposta = $this->ia->chamar(config('ia.timeout'), [
                'messages' => [['role' => 'system', 'content' => $sistema], ['role' => 'user', 'content' => $mensagem]],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.8,
            ]);
            if ($resposta->failed()) {
                throw ClienteIa::erro($resposta);
            }
            $dados = json_decode(ClienteIa::texto($resposta), true);
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
            $tipo = in_array($ideia['tipo'] ?? '', self::TIPOS, true) ? $ideia['tipo'] : 'dica';

            $resultado[] = [
                'titulo' => mb_substr(trim(PortuguesPt::limpar($ideia['titulo'])), 0, 150),
                'tipo' => $tipo,
                'notas' => mb_substr(trim((string) PortuguesPt::limpar(is_string($ideia['notas'] ?? null) ? $ideia['notas'] : '')), 0, 600),
                'quando' => $quando,
            ];
        }

        // Os temas aparecem no painel: também passam pelo revisor de português.
        $textos = [];
        foreach ($resultado as $i => $r) {
            $textos["$i.titulo"] = $r['titulo'];
            $textos["$i.notas"] = $r['notas'];
        }
        foreach ($this->ia->rever($textos) as $chave => $t) {
            [$i, $campo] = explode('.', $chave, 2);
            $resultado[(int) $i][$campo] = $t;
        }

        return $resultado;
    }
}
