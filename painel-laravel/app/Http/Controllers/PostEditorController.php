<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\User;
use App\Notifications\PostParaAprovar;
use App\Services\BancoFotos;
use App\Services\Pixabay;
use App\Services\PostWriter;
use App\Services\PostRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/** Criar e editar posts no painel: legenda, data, redes e a imagem feita com os modelos. */
class PostEditorController extends Controller
{
    public function create(Request $request): View
    {
        // ?data=AAAA-MM-DD vem do "+" do calendário.
        $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('data')) ? $request->query('data') : null;

        return view('painel.criar', ['modelos' => PostRenderer::MODELOS, 'data' => $data]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'modelo' => ['required', Rule::in(PostRenderer::MODELOS)],
            'title' => ['required', 'string', 'max:150'],
            'data' => ['nullable', 'date_format:Y-m-d'],
        ], ['title.required' => 'Dê um nome ao post (só aparece no painel).']);

        $post = Post::create([
            'title' => $data['title'],
            'design' => PostRenderer::exemplo($data['modelo']),
            'networks' => ['Instagram', 'Facebook'],
            'scheduled_at' => ! empty($data['data']) ? Carbon::parse($data['data'].' 10:00') : null,
            'status' => Post::CREATING,
            'source' => 'Criado no painel por '.$request->user()->name,
        ]);
        $post->reviews()->create(['user_id' => $request->user()->id, 'action' => 'created']);

        return redirect()->route('posts.edit', $post)
            ->with('ok', 'Post criado a partir do modelo. Mude os textos e carregue em “Guardar e gerar imagem”.');
    }

    public function edit(Post $post): View
    {
        return view('painel.editar', [
            'post' => $post,
            'modelos' => PostRenderer::MODELOS,
            'icones' => PostRenderer::ICONES,
            'pastaUrl' => PostRenderer::url($post),
            'pexels' => app(BancoFotos::class)->configurado(),
            'banco' => app(BancoFotos::class)->nome(),
            'ia' => app(PostWriter::class)->configurado(),
        ]);
    }

    /** Começar a usar um modelo num post que ainda não tem (ex.: uma ideia ou um post feito à parte). */
    public function useTemplate(Request $request, Post $post): RedirectResponse
    {
        $this->mustBeEditable($post);
        $data = $request->validate(['modelo' => ['required', Rule::in(PostRenderer::MODELOS)]]);

        $design = PostRenderer::exemplo($data['modelo']);
        $design['titulo'] = $post->title;
        $post->update(['design' => $design]);

        return redirect()->route('posts.edit', $post);
    }

    public function update(Request $request, Post $post, PostRenderer $renderer): RedirectResponse
    {
        $this->mustBeEditable($post);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'networks' => ['required', 'array', 'min:1'],
            'networks.*' => [Rule::in(Post::NETWORKS)],
            'scheduled_at' => ['nullable', 'date'],
            'design' => ['nullable', 'json', 'max:20000'],
        ], [
            'title.required' => 'O post precisa de um nome.',
            'networks.required' => 'Escolha pelo menos uma rede.',
            'caption.max' => 'A legenda tem um máximo de 2200 caracteres (limite do Instagram).',
        ]);

        $post->fill([
            'title' => $data['title'],
            'caption' => $data['caption'] ?? null,
            'networks' => array_values($data['networks']),
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ]);
        if (! empty($data['design'])) {
            $antigo = $post->design ?? [];
            $post->design = PostRenderer::limparDesign(json_decode($data['design'], true) ?: [], $post);
            // O editor não mostra estes campos: guardam-se para o botão "Outra foto".
            $post->design = $post->design + array_intersect_key($antigo, array_flip(['pesquisa', 'fotos_usadas']));
            $post->image_alt = trim(str_replace(['*', "\n"], ['', ' '], $post->design['titulo'] ?? $post->title));
        }
        $post->save();

        if (in_array($post->status, [Post::PENDING, Post::REVISING], true)) {
            $post->reviews()->create(['user_id' => $request->user()->id, 'action' => 'edited']);
        }

        if (! $post->design) {
            return redirect()->route('posts.edit', $post)->with('ok', 'Alterações guardadas.');
        }

        try {
            $resultado = $renderer->render($post);
        } catch (RuntimeException $e) {
            report($e);

            return redirect()->route('posts.edit', $post)
                ->with('erro', 'As alterações foram guardadas, mas a imagem não foi gerada: '.$e->getMessage());
        }
        $post->update(['image_path' => $resultado['path']]);

        return redirect()->route('posts.edit', $post)
            ->with('ok', 'Alterações guardadas e imagem gerada.')
            ->with('avisos', $resultado['avisos']);
    }

    /** Envia uma imagem (fundo, foto do produto ou logótipo do parceiro) para a pasta do post. */
    public function upload(Request $request, Post $post): JsonResponse
    {
        $this->mustBeEditable($post);
        $request->validate([
            'ficheiro' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:20480'],
        ], [
            'ficheiro.mimes' => 'Use uma imagem PNG, JPG ou WEBP.',
            'ficheiro.max' => 'A imagem tem de ter menos de 20 MB.',
        ]);

        $ficheiro = $request->file('ficheiro');
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$ficheiro->getMimeType()] ?? 'png';
        $base = Str::limit(Str::slug(pathinfo($ficheiro->getClientOriginalName(), PATHINFO_FILENAME)), 50, '') ?: 'imagem';
        if (str_starts_with($base, 'post-')) {
            $base = 'img-'.$base; // "post-*.png" é o nome das imagens geradas
        }
        $nome = "{$base}.{$ext}";
        File::ensureDirectoryExists(PostRenderer::pasta($post));
        $ficheiro->move(PostRenderer::pasta($post), $nome);

        return response()->json(['nome' => $nome]);
    }

    /** Procurar fotos no banco de fotos (editor: "Procurar imagem"). Em português, a IA traduz primeiro para inglês. */
    public function searchImages(Request $request, BancoFotos $pexels, PostWriter $escritor): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'max:100'], 'formato' => ['nullable', 'string']]);
        $pesquisa = $data['q'];
        if (Pixabay::parecePortugues($pesquisa) && $escritor->configurado()) {
            try {
                $pesquisa = $escritor->pesquisaEmIngles($pesquisa);
            } catch (\Throwable $e) {
                report($e); // sem tradução, pesquisa como foi escrita
            }
        }
        try {
            return response()->json(['pesquisa' => $pesquisa, 'fotos' => $pexels->procurar($pesquisa, $data['formato'] ?? '4:5')]);
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
    public function otherPhoto(Request $request, Post $post, BancoFotos $banco, PostWriter $escritor, PostRenderer $renderer): RedirectResponse
    {
        $this->mustBeEditable($post);
        abort_unless($post->design && $banco->configurado(), 409, 'Este post não usa os modelos ou não há banco de fotos.');

        $design = $post->design;
        $pesquisa = $design['pesquisa'] ?? '';
        $tema = trim($post->title.' — '.str_replace(['*', "\n"], ['', ' '], (string) ($design['titulo'] ?? '')));
        try {
            if ($pesquisa === '') {
                // Post sem pesquisa guardada (feito à mão): a IA tira as palavras do tema.
                $pesquisa = $escritor->configurado() ? $escritor->pesquisaEmIngles($tema) : $post->title;
            }
            $usadas = $design['fotos_usadas'] ?? [];
            $candidatas = array_values(array_filter(
                $banco->procurar($pesquisa, $design['formato'] ?? '4:5', 24),
                fn ($f) => ! in_array($f['id'], $usadas, true)
            ));
            $id = $escritor->configurado() ? $escritor->escolherFoto($tema, array_slice($candidatas, 0, 12)) : ($candidatas[0]['id'] ?? null);
            if (! $id) {
                return back()->with('erro', 'Não encontrei outra foto adequada para “'.$post->title.'”. Experimente “Procurar imagem” no Editar.');
            }

            $foto = $banco->descarregar($id, $post);
            $anterior = $design['foto'] ?? null;
            $design['foto'] = $foto['nome'];
            $design['tom'] = $design['tom'] ?? 'suave';
            $design['pesquisa'] = $pesquisa;
            $design['fotos_usadas'] = array_merge($usadas, [$id]);
            unset($design['icone'], $design['fundo']);
            $post->design = PostRenderer::limparDesign($design, $post);
            $post->save();

            $imagem = $renderer->render($post);
            $post->update(['image_path' => $imagem['path']]);
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

    /** Botões da IA na legenda (editor): devolve a legenda nova; só fica guardada quando se carrega em Guardar. */
    public function captionAi(Request $request, Post $post, PostWriter $escritor): JsonResponse
    {
        $this->mustBeEditable($post);
        $data = $request->validate([
            'acao' => ['required', Rule::in(array_keys(PostWriter::ACOES_LEGENDA))],
            'legenda' => ['nullable', 'string', 'max:2200'],
            'titulo' => ['nullable', 'string', 'max:300'],
            'texto' => ['nullable', 'string', 'max:600'],
        ]);
        $contexto = trim($post->title.'. '.str_replace(['*', "\n"], ['', ' '], ($data['titulo'] ?? '').'. '.($data['texto'] ?? '')), ' .');

        try {
            return response()->json(['legenda' => $escritor->legenda($data['acao'], trim($data['legenda'] ?? ''), $contexto)]);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /** Mudar só a data de publicação, a partir da lista de posts. */
    public function reschedule(Request $request, Post $post): RedirectResponse
    {
        $this->mustBeEditable($post);
        $data = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
        ], ['scheduled_at.after' => 'A data de publicação tem de ser no futuro.']);

        $antes = $post->whenLabel();
        $post->update(['scheduled_at' => Carbon::parse($data['scheduled_at'])]);
        $post->reviews()->create([
            'user_id' => $request->user()->id,
            'action' => 'rescheduled',
            'comment' => $antes.' → '.$post->whenLabel(),
        ]);

        return back()->with('ok', "“{$post->title}” passa a ser publicado {$post->whenLabel()}.");
    }

    public function submit(Request $request, Post $post): RedirectResponse
    {
        $this->mustBeEditable($post);
        abort_if($post->status === Post::PENDING, 409, 'Este post já está à espera de aprovação.');
        if (! $post->image_path) {
            return back()->with('erro', 'Gere a imagem antes de enviar para aprovação.');
        }
        if (! $post->scheduled_at) {
            return back()->with('erro', 'Escolha o dia e a hora de publicação antes de enviar para aprovação.');
        }

        $post->status = Post::PENDING;
        $post->change_request = null;
        $post->save();
        $post->reviews()->create(['user_id' => $request->user()->id, 'action' => 'submitted']);

        // Avisa os outros aprovadores. Se o email falhar, o post fica na fila na mesma.
        try {
            Notification::send(User::whereKeyNot($request->user()->id)->get(), new PostParaAprovar($post));
        } catch (Throwable $e) {
            report($e);
        }

        return redirect()->route('painel')->with('ok', "“{$post->title}” foi enviado para aprovação.");
    }

    private function mustBeEditable(Post $post): void
    {
        abort_unless($post->isEditable(), 409,
            'Este post já foi aprovado ou publicado. Desfaça a aprovação para o alterar.');
    }

}
