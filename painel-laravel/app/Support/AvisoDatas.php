<?php

namespace App\Support;

use App\Http\Controllers\CalendarioController;
use Illuminate\Support\Carbon;

/**
 * Textos do aviso a partir do dia de publicação. O aviso sai na véspera ("Amanhã estamos fechados"):
 * a data na imagem é o dia seguinte e "Voltamos na …" é o próximo dia útil (sem fins de semana nem feriados).
 * O editor.js faz o mesmo cálculo quando se muda a data no editor.
 */
class AvisoDatas
{
    /** @return array{data: string, texto: string} */
    public static function para(Carbon $publicacao): array
    {
        $fecho = $publicacao->copy()->startOfDay()->addDay();
        $volta = $fecho->copy();
        do {
            $volta->addDay();
        } while ($volta->isWeekend() || isset(CalendarioController::feriados($volta->year)[$volta->format('Y-m-d')]));

        return [
            'data' => ucfirst(self::DIAS[$fecho->dayOfWeek]).', '.$fecho->day.' de '.self::MESES[$fecho->month - 1],
            'texto' => 'Voltamos na '.self::DIAS[$volta->dayOfWeek].'. Obrigado pela compreensão!',
        ];
    }

    /**
     * Acerta o dia da semana das datas escritas pela IA ("Sábado, 30 de novembro" → "Segunda-feira, 30 de novembro"):
     * a IA engana-se muitas vezes a contar os dias. O ano é o da data mais próxima a partir de 2 meses antes de $referencia
     * (a data de publicação). Mantém o estilo: maiúscula, tudo em maiúsculas e "-feira" ou não.
     */
    public static function acertarDiasDaSemana(string $texto, Carbon $referencia): string
    {
        $meses = implode('|', self::MESES);

        // O "no próximo" / "neste" antes do dia também se acerta: "no próximo sábado" → "na próxima segunda-feira".
        return preg_replace_callback(
            '/(?<pre>(?:\b(?:no|na|o|a|neste|nesta|este|esta|pr[óo]ximo|pr[óo]xima)\s+){0,2})\b(segunda|terça|terca|quarta|quinta|sexta|sábado|sabado|domingo)(-feira)?(,?\s+(?:dia\s+)?)(\d{1,2})(º|\.º)?(\s+de\s+)('.$meses.')\b/iu',
            function ($m) use ($referencia) {
                $pre = $m['pre'];
                $m = array_values(array_filter($m, 'is_int', ARRAY_FILTER_USE_KEY)); // [0] tudo, [1] pre, [2] dia, …
                array_splice($m, 1, 1); // tira o "pre": os grupos ficam como antes ([1] dia da semana … [7] mês)
                $mes = array_search(mb_strtolower($m[7]), self::MESES, true) + 1;
                $dia = (int) $m[4];
                $inicio = $referencia->copy()->startOfDay()->subMonths(2);
                $data = null;
                foreach ([$inicio->year, $inicio->year + 1] as $ano) {
                    if (checkdate($mes, $dia, $ano) && ($d = Carbon::create($ano, $mes, $dia))->gte($inicio)) {
                        $data = $d;
                        break;
                    }
                }
                if (! $data) {
                    return $m[0];
                }
                $certo = self::DIAS[$data->dayOfWeek];
                $semFeira = ! $m[2] && ! preg_match('/^(s[áa]bado|domingo)$/iu', $m[1]); // "sexta, 30" (sábado e domingo não têm "-feira")
                if ($semFeira && str_ends_with($certo, '-feira')) {
                    $certo = substr($certo, 0, -6); // "sexta, 30" → "segunda, 30"
                }
                if ($m[1] === mb_strtoupper($m[1]) && mb_strlen($m[1]) > 1) {
                    $certo = mb_strtoupper($certo);
                } elseif (mb_substr($m[1], 0, 1) === mb_strtoupper(mb_substr($m[1], 0, 1))) {
                    $certo = mb_strtoupper(mb_substr($certo, 0, 1)).mb_substr($certo, 1);
                }

                // segunda a sexta são "a"; sábado e domingo são "o"
                $genero = $data->isWeekend()
                    ? ['na' => 'no', 'a' => 'o', 'nesta' => 'neste', 'esta' => 'este', 'próxima' => 'próximo', 'proxima' => 'proximo']
                    : ['no' => 'na', 'o' => 'a', 'neste' => 'nesta', 'este' => 'esta', 'próximo' => 'próxima', 'proximo' => 'proxima'];
                $pre = preg_replace_callback('/\p{L}+/u', function ($p) use ($genero) {
                    $novo = $genero[mb_strtolower($p[0])] ?? $p[0];

                    return mb_substr($p[0], 0, 1) === mb_strtoupper(mb_substr($p[0], 0, 1))
                        ? ($p[0] === mb_strtoupper($p[0]) && mb_strlen($p[0]) > 1 ? mb_strtoupper($novo) : mb_strtoupper(mb_substr($novo, 0, 1)).mb_substr($novo, 1))
                        : $novo;
                }, $pre);

                return $pre.$certo.$m[3].$m[4].$m[5].$m[6].$m[7];
            },
            $texto
        ) ?? $texto;
    }

    /** Calendário dos próximos dias para a IA ("seg 30/11; ter 1/12 (feriado: …)"): assim não conta os dias de cabeça. */
    public static function calendarioParaIa(int $dias = 45): string
    {
        $curto = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
        $feriados = CalendarioController::feriados(now()->year) + CalendarioController::feriados(now()->year + 1);
        $linhas = [];
        for ($d = now()->startOfDay(), $i = 0; $i < $dias; $i++, $d->addDay()) {
            $f = $feriados[$d->format('Y-m-d')] ?? null;
            $linhas[] = $curto[$d->dayOfWeek].' '.$d->day.'/'.$d->month.($f ? ' (feriado: '.$f.')' : '');
        }

        return implode('; ', $linhas);
    }

    /** Nomes completos ("terça-feira"): o Carbon em pt_PT escreve só "terça". Domingo = 0, como no JS. */
    private const DIAS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

    private const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    /** Feriados (AAAA-MM-DD) deste ano e do próximo, para o editor.js fazer o mesmo cálculo. */
    public static function feriados(): array
    {
        return array_keys(CalendarioController::feriados(now()->year) + CalendarioController::feriados(now()->year + 1));
    }
}
