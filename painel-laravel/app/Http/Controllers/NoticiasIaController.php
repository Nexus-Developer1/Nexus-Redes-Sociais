<?php

namespace App\Http\Controllers;

use App\Console\Commands\NoticiasIaCommand;
use App\Models\NoticiaIa;
use App\Models\ResumoIa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Página "Notícias de IA", em três separadores: o resumo (o último ou um anterior), todas as notícias recolhidas
 * (com filtro por fonte) e os resumos anteriores.
 */
class NoticiasIaController extends Controller
{
    public const SEPARADORES = ['resumo', 'noticias', 'anteriores'];

    public function index(Request $request): View
    {
        $ver = in_array($request->query('ver'), self::SEPARADORES, true) ? $request->query('ver') : 'resumo';
        $resumos = ResumoIa::latest('fim')->limit(30)->get(['id', 'inicio', 'fim', 'conteudo', 'n_noticias', 'created_at']);
        $resumo = $request->filled('resumo') ? ResumoIa::find((int) $request->query('resumo')) : $resumos->first();

        $porFonte = NoticiaIa::selectRaw('fonte, count(*) as n')->groupBy('fonte')->orderBy('fonte')->pluck('n', 'fonte');
        $fonte = $porFonte->has($request->query('fonte')) ? $request->query('fonte') : null;
        $procura = mb_substr(trim((string) $request->query('q')), 0, 80);
        $noticias = $ver === 'noticias'
            ? NoticiaIa::when($fonte, fn ($q) => $q->where('fonte', $fonte))
                ->when($procura !== '', fn ($q) => $q->where(fn ($q) => $q->where('titulo', 'like', '%'.$procura.'%')
                    ->orWhere('descricao', 'like', '%'.$procura.'%')))
                ->orderByDesc('publicada_em')->simplePaginate(40)->withQueryString()
            : collect();

        $noticiasDoResumo = $resumo?->noticias() ?? collect();

        return view('painel.noticias', [
            'ver' => $ver,
            'resumo' => $resumo,
            'noticiasDoResumo' => $noticiasDoResumo,
            'fontesDoResumo' => $noticiasDoResumo->pluck('fonte')->unique()->count(),
            'resumos' => $resumos,
            'noticias' => $noticias,
            'porFonte' => $porFonte,
            'fonte' => $fonte,
            'procura' => $procura,
            'totalNoticias' => $porFonte->sum(),
            'fontes' => count((array) config('ia.fontes_noticias')),
            'aTrabalhar' => Cache::has(NoticiasIaCommand::A_TRABALHAR) || Cache::has(NoticiasIaCommand::PEDIDO),
        ]);
    }

    /** "Recolher agora": o agente trabalha no minuto seguinte (no servidor, pelo agendador). */
    public function recolher(): RedirectResponse
    {
        Cache::put(NoticiasIaCommand::PEDIDO, 1, now()->addMinutes(10));

        return redirect()->route('noticias')
            ->with('ok', 'O agente está a recolher as notícias e a fazer o resumo. Aparece aqui sozinho dentro de 1 a 3 minutos.');
    }
}
