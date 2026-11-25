<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Notifications\PostParaAprovar;
use App\Services\BancoFotos;
use App\Services\Ia\ClienteIa;
use App\Services\Ia\PostWriter;
use App\Services\PostRenderer;
use App\Support\AvisoDatas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Criar e editar posts no painel: legenda, data, redes e a imagem feita com os modelos.
 * As imagens (enviar, banco de fotos, "Outra foto") estão no PostImagensController e o email aos parceiros no PostParceirosController.
 */
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
            'formato' => ['nullable', 'in:imagem,carrossel'],
        ], ['title.required' => 'Dê um nome ao post (só aparece no painel).']);

        $design = PostRenderer::exemplo($data['modelo']);
        if (($data['formato'] ?? 'imagem') === 'carrossel') {
            if (in_array($data['modelo'], ['aviso', 'evento'], true)) {
                return back()->withInput()->withErrors(['formato' => 'Avisos e eventos não têm carrossel: escolha “Imagem única” ou outro tipo.']);
            }
            $design = self::comoCarrossel($design);
        }
        $quando = ! empty($data['data']) ? Carbon::parse($data['data'].' 10:00') : null;
        if ($quando && $data['modelo'] === 'aviso') {
            $design = AvisoDatas::para($quando) + $design; // data e "Voltamos na…" do dia do post, não os do exemplo
        }

        $post = Post::create([
            'title' => $data['title'],
            'design' => $design,
            'networks' => ['Instagram', 'Stories', 'Facebook', 'LinkedIn'],
            'scheduled_at' => $quando,
            'status' => Post::CREATING,
            'source' => 'Criado no painel por '.$request->user()->name,
        ]);
        $post->reviews()->create(['user_id' => $request->user()->id, 'action' => 'created']);

        return redirect()->route('posts.edit', $post)
            ->with('ok', 'Post criado a partir do modelo. Mude os textos e carregue em “Guardar e gerar imagem”.');
    }

    /** Exemplo de um modelo em carrossel: os pontos do exemplo (ou 3 slides de exemplo) passam a slides. */
    private static function comoCarrossel(array $design): array
    {
        $slides = [];
        foreach (array_values((array) ($design['pontos'] ?? [])) as $i => $p) {
            $texto = is_array($p) ? (string) ($p['texto'] ?? '') : (string) $p;
            if ($texto !== '') {
                $slides[] = ['destaque' => is_array($p) && ! empty($p['destaque']) ? $p['destaque'] : (string) ($i + 1),
                    'titulo' => $texto, 'texto' => 'Explique este ponto numa ou duas frases.'];
            }
        }
        if (! $slides) {
            foreach ([1, 2, 3] as $n) {
                $slides[] = ['destaque' => (string) $n, 'titulo' => "Ponto {$n}", 'texto' => 'Explique este ponto numa ou duas frases.'];
            }
        }
        unset($design['pontos']);
        if (($design['formato'] ?? '4:5') === '9:16') {
            $design['formato'] = '4:5';
        }

        return $design + ['carrossel' => true, 'slides' => $slides];
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
            'ia' => app(ClienteIa::class)->configurado(),
        ]);
    }

    /** Começar a usar um modelo num post que ainda não tem (ex.: uma ideia ou um post feito à parte). */
    public function useTemplate(Request $request, Post $post): RedirectResponse
    {
        $this->mustBeEditable($post);
        $data = $request->validate(['modelo' => ['required', Rule::in(PostRenderer::MODELOS)]]);

        $design = PostRenderer::exemplo($data['modelo']);
        $design['titulo'] = $post->title;
        if ($data['modelo'] === 'aviso' && $post->scheduled_at) {
            $design = AvisoDatas::para($post->scheduled_at) + $design;
        }
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
            'enviar_parceiros' => ['nullable', 'boolean'],
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
        // Depois de enviado aos parceiros já não se muda (não se "desenvia" um email).
        $querParceiros = (bool) ($data['enviar_parceiros'] ?? $post->enviar_parceiros);
        if ($querParceiros !== $post->enviar_parceiros && ! $post->parceiros_enviado_em) {
            $post->enviar_parceiros = $querParceiros;
            $post->reviews()->create(['user_id' => $request->user()->id, 'action' => $querParceiros ? 'partners_on' : 'partners_off']);
        }
        if (! empty($data['design'])) {
            $antigo = $post->design ?? [];
            $post->design = PostRenderer::limparDesign(json_decode($data['design'], true) ?: [], $post);
            // O editor não mostra estes campos: guardam-se para o botão "Outra foto".
            $post->design = $post->design + array_intersect_key($antigo, array_flip(['pesquisa', 'pesquisas', 'fotos_usadas']));
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
        $post->guardarImagens($resultado);

        return redirect()->route('posts.edit', $post)
            ->with('ok', 'Alterações guardadas e imagem gerada.')
            ->with('avisos', $resultado['avisos']);
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
            PostParaAprovar::enviar($post, exceto: $request->user());
        } catch (Throwable $e) {
            report($e);
        }

        return redirect()->route('painel')->with('ok', "“{$post->title}” foi enviado para aprovação.");
    }

    /**
     * Eliminar um post de vez (com as imagens e o histórico). Só se nunca foi aprovado nem publicado
     * e a IA não estiver a trabalhar nele (ver Post::motivoParaNaoEliminar).
     */
    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $vemDoEditor = url()->previous() === route('posts.edit', $post);
        $voltar = fn () => $vemDoEditor ? redirect()->route('painel') : back();

        if ($motivo = $post->motivoParaNaoEliminar()) {
            return back()->with('erro', $motivo);
        }
        // Só apaga se ninguém mexeu no post entretanto (ex.: foi aprovado ou a IA pegou nele).
        $apagado = Post::whereKey($post->id)
            ->whereIn('status', Post::ELIMINAVEIS)
            ->where('status', $post->status)
            ->where('updated_at', $post->updated_at)
            ->whereNull('facebook_post_id')->whereNull('instagram_media_id')->whereNull('instagram_story_id')
            ->delete();
        if (! $apagado) {
            return back()->with('erro', 'Este post mudou entretanto (a IA ou outra pessoa mexeu nele). Atualize a página e tente outra vez.');
        }

        // Imagens do post: a pasta dos modelos e, nos posts antigos feitos à parte, a imagem solta.
        File::deleteDirectory(PostRenderer::pasta($post));
        $solta = (string) $post->image_path;
        if (preg_match('#^imagens-posts/[^/]+$#', $solta) && ! Post::where('image_path', $solta)->exists()) {
            File::delete(public_path($solta));
        }
        \Illuminate\Support\Facades\Log::info('Post eliminado no painel', [
            'post' => $post->id, 'titulo' => $post->title, 'estado' => $post->status, 'por' => $request->user()->id,
        ]);

        return $voltar()->with('ok', "“{$post->title}” foi eliminado.");
    }
}
