<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Services\BancoFotos;
use App\Services\Ia\ClienteIa;
use App\Services\Ia\FotosIa;
use App\Services\ImagensSeguras;
use App\Services\Pixabay;
use App\Services\PostRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/** Imagens do post: enviar uma imagem, procurar no banco de fotos e "Outra foto". */
class PostImagensController extends Controller
{
    /** Envia uma imagem (fundo, foto do produto ou logótipo do parceiro) para a pasta do post. */
    public function upload(Request $request, Post $post): JsonResponse
    {
        $this->mustBeEditable($post);
        $request->validate([
            'ficheiro' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:20480', ...ImagensSeguras::regrasUpload()],
        ], [
            'ficheiro.mimes' => 'Use uma imagem PNG, JPG ou WEBP.',
            'ficheiro.max' => 'A imagem tem de ter menos de 20 MB.',
            'ficheiro.dimensions' => 'A imagem é demasiado grande: no máximo '.ImagensSeguras::MAX_LADO.' píxeis de lado.',
        ]);

        $ficheiro = $request->file('ficheiro');
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$ficheiro->getMimeType()] ?? 'png';
        $base = Str::limit(Str::slug(pathinfo($ficheiro->getClientOriginalName(), PATHINFO_FILENAME)), 50, '') ?: 'imagem';
        if (preg_match('/^(post|pixabay|pexels|bib)-/', $base)) {
            $base = 'img-'.$base; // nomes reservados: imagens geradas, fotos dos bancos e da biblioteca
        }
        // sufixo aleatório: dois uploads com o mesmo nome (ex.: logo.png) não se substituem
        $nome = $base.'-'.strtolower(Str::random(6)).'.'.$ext;
        File::ensureDirectoryExists(PostRenderer::pasta($post));
        $ficheiro->move(PostRenderer::pasta($post), $nome);

        return response()->json(['nome' => $nome]);
    }

    /** Procurar fotos no banco de fotos (editor: "Procurar imagem"). Em português, a IA traduz primeiro para inglês. */
    public function searchImages(Request $request, BancoFotos $pexels, ClienteIa $ia, FotosIa $fotosIa): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'max:100'], 'formato' => ['nullable', 'string']]);
        $pesquisa = $data['q'];
        if (Pixabay::parecePortugues($pesquisa) && $ia->configurado()) {
            try {
                $pesquisa = $fotosIa->pesquisaEmIngles($pesquisa);
            } catch (\Throwable $e) {
                report($e); // sem tradução, pesquisa como foi escrita
            }
        }
        try {
            // As que já estão noutros posts vêm marcadas, para não se repetir sem querer.
            $emUso = Post::fotosEmUso()['banco'];
            $fotos = array_map(fn ($f) => $f + ['usada' => in_array($pexels->chave($f['id']), $emUso, true)],
                $pexels->procurar($pesquisa, $data['formato'] ?? '4:5'));

            return response()->json(['pesquisa' => $pesquisa, 'fotos' => $fotos]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /** Usar uma foto do Pexels como imagem de fundo do post. */
    public function useStockImage(Request $request, Post $post, BancoFotos $pexels): JsonResponse
    {
        $this->mustBeEditable($post);
        $data = $request->validate(['id' => ['required', 'integer', 'min:1']]);
        try {
            return response()->json($pexels->descarregar($data['id'], $post));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /**
     * "Outra foto" no cartão do post: procura fotos para o tema, a IA escolhe uma ainda não usada
     * neste post, e a imagem é refeita. O resto do post não muda.
     */
    public function otherPhoto(Request $request, Post $post, BancoFotos $banco, ClienteIa $ia, FotosIa $fotosIa, PostRenderer $renderer): RedirectResponse
    {
        $this->mustBeEditable($post);
        abort_unless($post->design && $banco->configurado(), 409, 'Este post não usa os modelos ou não há banco de fotos.');

        $design = $post->design;
        $pesquisa = $design['pesquisa'] ?? '';
        $tema = trim($post->title.' — '.str_replace(['*', "\n"], ['', ' '], (string) ($design['titulo'] ?? '')));
        try {
            // Cada "Outra foto" procura outra cena (a IA dá uma pesquisa diferente das anteriores);
            // fora ficam as fotos já usadas aqui ou noutros posts e as da mesma série (quase iguais).
            $pesquisas = $design['pesquisas'] ?? array_values(array_filter([$pesquisa]));
            $usadas = $design['fotos_usadas'] ?? [];
            $atual = preg_match('/^(?:pixabay|pexels)-(\d+)\.jpg$/', (string) ($design['foto'] ?? ''), $m) ? [(int) $m[1]] : [];
            $nova = $ia->configurado() ? $fotosIa->pesquisaNova($tema, $pesquisas ?: [$post->title]) : '';
            $candidatas = [];
            foreach (array_unique(array_filter([$nova, $pesquisa, $post->title])) as $tentativa) {
                $candidatas = $banco->procurarNovas($tentativa, $design['formato'] ?? '4:5', 24, $post, [...$usadas, ...$atual]);
                if ($candidatas) {
                    $pesquisa = $tentativa;
                    break;
                }
            }
            try {
                $id = $ia->configurado() ? $fotosIa->escolher($tema, array_slice($candidatas, 0, 12)) : ($candidatas[0]['id'] ?? null);
            } catch (RuntimeException $e) {
                $id = $candidatas[0]['id'] ?? null; // a IA não respondeu (limite do plano): a mais relevante da pesquisa
            }
            if (! $id) {
                return back()->with('erro', 'Não encontrei outra foto adequada para “'.$post->title.'”. Experimente “Procurar imagem” no Editar.');
            }

            $foto = $banco->descarregar($id, $post);
            $anterior = $design['foto'] ?? null;
            $design['foto'] = $foto['nome'];
            $design['tom'] = $design['tom'] ?? 'suave';
            $design['pesquisa'] = $pesquisa;
            $design['pesquisas'] = array_slice(array_values(array_unique([...$pesquisas, $pesquisa])), -10);
            $design['fotos_usadas'] = array_values(array_unique([...$usadas, ...$atual, $id]));
            unset($design['icone'], $design['fundo']);
            $post->design = PostRenderer::limparDesign($design, $post);
            $post->save();

            $post->guardarImagens($renderer->render($post));
            // A foto do banco que saiu já não faz falta.
            if ($anterior && $anterior !== $foto['nome'] && preg_match('/^(pixabay|pexels)-\d+\.jpg$/', $anterior)) {
                File::delete(PostRenderer::pasta($post).'/'.$anterior);
            }
        } catch (RuntimeException $e) {
            report($e);

            return back()->with('erro', 'Não foi possível trocar a foto: '.$e->getMessage());
        }

        $post->reviews()->create(['user_id' => $request->user()->id, 'action' => 'photo_changed',
            'comment' => 'Foto: '.$foto['fotografo'].' ('.$banco->nome().')']);

        return back()->with('ok', 'Foto trocada em “'.$post->title.'”.');
    }
}
