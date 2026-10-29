<?php

namespace App\Http\Controllers;

use App\Models\ImagemBiblioteca;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Biblioteca da NEXUS: carregar, descrever e remover imagens; usá-las nos posts. */
class BibliotecaController extends Controller
{
    public function index(Request $request): View
    {
        $tipo = array_key_exists($request->query('tipo'), ImagemBiblioteca::TIPOS) ? $request->query('tipo') : null;

        return view('painel.biblioteca', [
            'tipo' => $tipo,
            'imagens' => ImagemBiblioteca::when($tipo, fn ($q) => $q->where('tipo', $tipo))->latest()->get(),
            'contagem' => ImagemBiblioteca::selectRaw('tipo, count(*) as n')->groupBy('tipo')->pluck('n', 'tipo'),
        ]);
    }

    /** Carregar uma ou várias imagens do mesmo tipo, com a mesma descrição (pode mudar-se depois). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(ImagemBiblioteca::TIPOS))],
            'descricao' => ['required', 'string', 'max:300'],
            'ficheiros' => ['required', 'array', 'max:20'],
            'ficheiros.*' => ['file', 'mimes:png,jpg,jpeg,webp', 'max:20480'],
        ], [
            'descricao.required' => 'Escreva uma descrição: é o que a IA lê para escolher a imagem.',
            'ficheiros.required' => 'Escolha pelo menos uma imagem.',
            'ficheiros.*.mimes' => 'Use imagens PNG, JPG ou WEBP.',
            'ficheiros.*.max' => 'Cada imagem tem de ter menos de 20 MB.',
        ]);

        File::ensureDirectoryExists(ImagemBiblioteca::pasta());
        foreach ($data['ficheiros'] as $ficheiro) {
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$ficheiro->getMimeType()] ?? 'png';
            $nome = Str::slug(Str::limit(pathinfo($ficheiro->getClientOriginalName(), PATHINFO_FILENAME), 40, '')) ?: 'imagem';
            $nome = $data['tipo'].'-'.$nome.'-'.Str::lower(Str::random(6)).'.'.$ext;
            $ficheiro->move(ImagemBiblioteca::pasta(), $nome);
            ImagemBiblioteca::create([
                'tipo' => $data['tipo'], 'descricao' => trim($data['descricao']),
                'ficheiro' => $nome, 'user_id' => $request->user()->id,
            ]);
        }
        $n = count($data['ficheiros']);

        return redirect()->route('biblioteca', ['tipo' => $data['tipo']])
            ->with('ok', $n === 1 ? 'Imagem guardada na biblioteca.' : "{$n} imagens guardadas na biblioteca.");
    }

    public function update(Request $request, ImagemBiblioteca $imagem): RedirectResponse
    {
        $data = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(ImagemBiblioteca::TIPOS))],
            'descricao' => ['required', 'string', 'max:300'],
        ]);
        $imagem->update($data);

        return back()->with('ok', 'Descrição guardada.');
    }

    public function destroy(ImagemBiblioteca $imagem): RedirectResponse
    {
        $imagem->delete();

        return back()->with('ok', 'Imagem removida da biblioteca. Os posts que já a usavam ficam iguais.');
    }

    /** Lista para o editor ("Da biblioteca…"). */
    public function lista(Request $request): JsonResponse
    {
        $tipo = $request->query('tipo');
        abort_unless(array_key_exists($tipo, ImagemBiblioteca::TIPOS), 422);

        return response()->json(['imagens' => ImagemBiblioteca::where('tipo', $tipo)->latest()->get()
            ->map(fn ($i) => ['id' => $i->id, 'url' => $i->url(), 'descricao' => $i->descricao])]);
    }

    /** Usar uma imagem da biblioteca num campo do post (foto, produto ou parceiro): copia-a para a pasta do post. */
    public function usar(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->isEditable(), 409, 'Este post já foi aprovado ou publicado.');
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'campo' => ['required', Rule::in(array_keys(ImagemBiblioteca::PARA_CAMPO))],
        ]);
        $imagem = ImagemBiblioteca::where('tipo', ImagemBiblioteca::PARA_CAMPO[$data['campo']])->findOrFail($data['id']);

        return response()->json(['nome' => $imagem->copiarPara($post)]);
    }
}
