<?php

namespace App\Services;

use App\Http\Controllers\CalendarioController;
use Illuminate\Support\Carbon;

/**
 * Datas que dão (ou impedem) posts: feriados nacionais (a empresa fecha → aviso na véspera)
 * e dias ligados à tecnologia que interessam aos clientes da NEXUS.
 */
class DatasEspeciais
{
    /** @return array<string, string> 'AAAA-MM-DD' => "Feriado: Natal" ou "Dia Mundial do Backup" */
    public static function entre(Carbon $inicio, Carbon $fim): array
    {
        $datas = [];
        foreach (range($inicio->year, $fim->year) as $ano) {
            foreach (CalendarioController::feriados($ano) as $dia => $nome) {
                $datas[$dia] = ($nome === 'Páscoa' ? '' : 'Feriado: ').$nome; // a Páscoa é domingo
            }
            foreach (self::tecnologia($ano) as $dia => $nome) {
                $datas[$dia] = isset($datas[$dia]) ? $datas[$dia].' e '.$nome : $nome;
            }
        }
        $datas = array_filter($datas, fn ($dia) => $dia >= $inicio->format('Y-m-d') && $dia <= $fim->format('Y-m-d'), ARRAY_FILTER_USE_KEY);
        ksort($datas);

        // Outubro é o Mês Europeu da Cibersegurança: vale para todas as semanas de outubro.
        if ($inicio->month === 10 || $fim->month === 10) {
            $dia = ($inicio->month === 10 ? $inicio : $fim->copy()->startOfMonth())->format('Y-m-d');
            $mes = 'Mês Europeu da Cibersegurança (todo o mês de outubro)';
            $datas[$dia] = isset($datas[$dia]) ? $datas[$dia].' e '.$mes : $mes;
            ksort($datas);
        }

        return $datas;
    }

    /** @return array<string, string> */
    private static function tecnologia(int $ano): array
    {
        // n-ésimo dia da semana do mês (ex.: 2.ª terça de fevereiro)
        $n = fn (int $ordem, int $diaSemana, int $mes) => Carbon::create($ano, $mes, 1)->nthOfMonth($ordem, $diaSemana)->format('Y-m-d');

        return [
            "$ano-01-28" => 'Dia Europeu da Proteção de Dados',
            $n(2, Carbon::TUESDAY, 2) => 'Dia da Internet Mais Segura',
            "$ano-03-31" => 'Dia Mundial do Backup',
            $n(1, Carbon::THURSDAY, 5) => 'Dia Mundial da Password',
            "$ano-05-17" => 'Dia Mundial das Telecomunicações e da Sociedade da Informação',
            "$ano-06-30" => 'Dia Mundial das Redes Sociais',
            Carbon::create($ano, 11, 1)->nthOfMonth(4, Carbon::THURSDAY)->addDay()->format('Y-m-d') => 'Black Friday',
            "$ano-11-30" => 'Dia Internacional da Segurança Informática',
            "$ano-12-31" => 'Fim de ano (balanço e votos de bom ano)',
        ];
    }
}
