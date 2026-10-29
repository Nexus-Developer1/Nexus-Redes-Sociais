<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\User;
use App\Notifications\PostParaAprovar;
use App\Services\PostRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function create(): View
    {
        return view('painel.criar', ['modelos' => PostRenderer::MODELOS]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'modelo' => ['required', Rule::in(PostRenderer::MODELOS)],
            'title' => ['required', 'string', 'max:150'],
        ], ['title.required' => 'Dê um nome ao post (só aparece no painel).']);

        $post = Post::create([
            'title' => $data['title'],
            'design' => PostRenderer::exemplo($data['modelo']),
            'networks' => ['Instagram', 'Facebook'],
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
            $post->design = $this->limparDesign(json_decode($data['design'], true) ?: [], $post);
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

    /** Só guarda os campos que os modelos conhecem, com limites de tamanho. */
    private function limparDesign(array $d, Post $post): array
    {
        $texto = fn ($v, int $max) => is_scalar($v) ? mb_substr(trim(str_replace("\r", '', (string) $v)), 0, $max) : '';

        $limpo = [
            'modelo' => in_array($d['modelo'] ?? '', PostRenderer::MODELOS, true) ? $d['modelo'] : 'dica',
            'formato' => isset(PostRenderer::FORMATOS[$d['formato'] ?? '']) ? $d['formato'] : '4:5',
        ];
        foreach (['titulo' => 300, 'texto' => 600, 'etiqueta' => 40, 'rodape' => 80, 'site' => 60,
            'cta' => 80, 'data' => 80, 'hora' => 40] as $campo => $max) {
            if (isset($d[$campo]) && ($v = $texto($d[$campo], $max)) !== '') {
                $limpo[$campo] = $v;
            }
        }
        if (in_array($d['icone'] ?? '', PostRenderer::ICONES, true)) {
            $limpo['icone'] = $d['icone'];
        }
        if (! empty($d['pontos']) && is_array($d['pontos'])) {
            foreach (array_slice($d['pontos'], 0, 4) as $p) {
                if (is_array($p)) {
                    $limpo['pontos'][] = ['destaque' => $texto($p['destaque'] ?? '', 12), 'texto' => $texto($p['texto'] ?? '', 90)];
                } elseif (($v = $texto($p, 90)) !== '') {
                    $limpo['pontos'][] = $v;
                }
            }
        }
        if (! empty($d['evento']) && is_array($d['evento'])) {
            foreach (['dia' => 4, 'mes' => 12, 'hora' => 40, 'local' => 80] as $campo => $max) {
                if (($v = $texto($d['evento'][$campo] ?? '', $max)) !== '') {
                    $limpo['evento'][$campo] = $v;
                }
            }
        }
        // Imagens: só ficheiros que existem na pasta do post.
        foreach (['fundo', 'produto', 'parceiro'] as $campo) {
            $nome = (string) ($d[$campo] ?? '');
            if (preg_match('/^[a-z0-9-]+\.(png|jpg|webp)$/', $nome) && is_file(PostRenderer::pasta($post).'/'.$nome)) {
                $limpo[$campo] = $nome;
            }
        }

        return $limpo;
    }
}
