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

    /** Nomes completos ("terça-feira"): o Carbon em pt_PT escreve só "terça". Domingo = 0, como no JS. */
    private const DIAS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

    private const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    /** Feriados (AAAA-MM-DD) deste ano e do próximo, para o editor.js fazer o mesmo cálculo. */
    public static function feriados(): array
    {
        return array_keys(CalendarioController::feriados(now()->year) + CalendarioController::feriados(now()->year + 1));
    }
}
