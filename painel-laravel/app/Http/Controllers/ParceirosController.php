<?php

namespace App\Http\Controllers;

use App\Models\Parceiro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Lista de parceiros que recebem por email os posts marcados com "Enviar também aos parceiros". */
class ParceirosController extends Controller
{
    public function index(): View
    {
        $parceiros = Parceiro::orderByDesc('ativo')->orderBy('nome')->get();

        return view('painel.parceiros', [
            'parceiros' => $parceiros,
            'ativos' => $parceiros->where('ativo', true)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'nome' => ['required', 'string', 'max:150'],
            'empresa' => ['nullable', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', Rule::unique('parceiros', 'email')],
        ], [
            'email.unique' => 'Esse email já está na lista.',
            'email.email' => 'O email não parece válido.',
        ]);

        Parceiro::create($data);

        return back()->with('ok', "{$data['nome']} foi adicionado à lista.");
    }

    /**
     * Colar uma lista do Excel ou enviar um CSV. Emails que já estão na lista não mudam
     * (quem pediu para sair continua fora, mesmo que volte a aparecer numa lista).
     */
    public function importar(Request $request): RedirectResponse
    {
        $request->validate([
            'lista' => ['nullable', 'string', 'max:200000'],
            'ficheiro' => ['nullable', 'file', 'mimes:csv,txt', 'max:2048'],
        ], ['ficheiro.mimes' => 'Use um ficheiro CSV (no Excel: Guardar como → CSV).']);

        $texto = (string) $request->input('lista');
        if ($request->hasFile('ficheiro')) {
            $conteudo = (string) file_get_contents($request->file('ficheiro')->getRealPath());
            // O Excel em português grava CSV em Windows-1252
            $texto .= "\n".(mb_check_encoding($conteudo, 'UTF-8') ? $conteudo : mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252'));
        }
        [$lista, $ignoradas] = Parceiro::lerLista($texto);
        if (! $lista) {
            return back()->withInput()->with('erro', 'Não encontrei nenhum email na lista. Cada linha precisa de um email.');
        }

        $existentes = Parceiro::whereIn('email', array_column($lista, 'email'))->pluck('email')->all();
        $novos = array_filter($lista, fn ($p) => ! in_array($p['email'], $existentes, true));
        foreach ($novos as $p) {
            Parceiro::create($p);
        }

        $msg = count($novos).' '.(count($novos) === 1 ? 'parceiro adicionado' : 'parceiros adicionados');
        if ($existentes) {
            $msg .= ', '.count($existentes).' já estava'.(count($existentes) === 1 ? '' : 'm').' na lista';
        }
        if ($ignoradas) {
            $msg .= ', '.$ignoradas.' '.($ignoradas === 1 ? 'linha sem email ignorada' : 'linhas sem email ignoradas');
        }

        return back()->with('ok', $msg.'.');
    }

    /** "Deixou de receber" (ex.: pediu para sair) ou volta a receber. */
    public function estado(Parceiro $parceiro): RedirectResponse
    {
        $ativo = ! $parceiro->ativo;
        $parceiro->update(['ativo' => $ativo, 'saiu_em' => $ativo ? null : now()]);

        return back()->with('ok', $ativo ? "{$parceiro->nome} volta a receber os emails." : "{$parceiro->nome} deixa de receber os emails.");
    }

    /** Apagar de vez (ex.: email enganado). O registo dos emails já enviados a esta pessoa também sai. */
    public function destroy(Parceiro $parceiro): RedirectResponse
    {
        $parceiro->delete();

        return back()->with('ok', "{$parceiro->nome} foi removido da lista.");
    }
}
