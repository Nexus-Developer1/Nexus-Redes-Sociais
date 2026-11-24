<?php

namespace App\Http\Controllers;

use App\Console\Commands\NoticiasIaCommand;
use App\Models\NoticiaIa;
use App\Models\ResumoIa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/** Página "Notícias de IA": o último resumo do agente, os anteriores e as notícias recolhidas. */
class NoticiasIaController extends Controller
{
    public function index(Request $request): View
    {
        $resumos = ResumoIa::latest('fim')->limit(30)->get(['id', 'inicio', 'fim', 'conteudo', 'n_noticias']);
        $resumo = $request->filled('resumo') ? ResumoIa::find((int) $request->query('resumo')) : $resumos->first();

        return view('painel.noticias', [
            'resumo' => $resumo,
            'noticiasDoResumo' => $resumo?->noticias() ?? collect(),
            'resumos' => $resumos,
            'noticias' => NoticiaIa::orderByDesc('publicada_em')->limit(40)->get(),
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
