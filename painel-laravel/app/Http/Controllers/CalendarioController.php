<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Calendário do mês: todos os posts com data, os feriados nacionais e os dias sem nada. */
class CalendarioController extends Controller
{
    public function index(Request $request): View
    {
        $mes = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('mes'))
            ? Carbon::createFromFormat('!Y-m', $request->query('mes'))
            : now()->startOfMonth();
        $mes = $mes->locale('pt_PT');

        // A grelha começa à segunda-feira antes do dia 1 e acaba ao domingo depois do último dia.
        $inicio = $mes->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $fim = $mes->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $posts = Post::whereBetween('scheduled_at', [$inicio, $fim])
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy(fn (Post $p) => $p->scheduled_at->format('Y-m-d'));

        $feriados = self::feriados($mes->year);

        $dias = [];
        for ($d = $inicio->copy(); $d <= $fim; $d->addDay()) {
            $chave = $d->format('Y-m-d');
            $dias[] = [
                'data' => $d->copy(),
                'chave' => $chave,
                'doMes' => $d->month === $mes->month,
                'hoje' => $d->isToday(),
                'passado' => $d->lt(today()),
                'feriado' => $feriados[$chave] ?? null,
                'posts' => $posts[$chave] ?? collect(),
            ];
        }

        $doMes = Post::whereYear('scheduled_at', $mes->year)->whereMonth('scheduled_at', $mes->month)
            ->where('status', '!=', Post::REJECTED)->count();

        return view('painel.calendario', [
            'mes' => $mes,
            'dias' => $dias,
            'anterior' => $mes->copy()->subMonth()->format('Y-m'),
            'seguinte' => $mes->copy()->addMonth()->format('Y-m'),
            'totalMes' => $doMes,
            'semData' => Post::whereNull('scheduled_at')->whereNotIn('status', [Post::REJECTED, Post::PUBLISHED])->count(),
        ]);
    }

    /** Feriados nacionais de Portugal (data => nome). */
    public static function feriados(int $ano): array
    {
        $pascoa = self::pascoa($ano);
        $lista = [
            "$ano-01-01" => 'Ano Novo',
            $pascoa->copy()->subDays(2)->format('Y-m-d') => 'Sexta-feira Santa',
            $pascoa->format('Y-m-d') => 'Páscoa',
            "$ano-04-25" => 'Dia da Liberdade',
            "$ano-05-01" => 'Dia do Trabalhador',
            $pascoa->copy()->addDays(60)->format('Y-m-d') => 'Corpo de Deus',
            "$ano-06-10" => 'Dia de Portugal',
            "$ano-08-15" => 'Assunção de Nossa Senhora',
            "$ano-10-05" => 'Implantação da República',
            "$ano-11-01" => 'Dia de Todos os Santos',
            "$ano-12-01" => 'Restauração da Independência',
            "$ano-12-08" => 'Imaculada Conceição',
            "$ano-12-25" => 'Natal',
        ];
        ksort($lista);

        return $lista;
    }

    /** Domingo de Páscoa (algoritmo de Meeus/Jones/Butcher, calendário gregoriano). */
    private static function pascoa(int $ano): Carbon
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($ano, $mes, $dia);
    }
}
