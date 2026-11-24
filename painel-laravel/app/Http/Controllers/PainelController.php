<?php

namespace App\Http\Controllers;

use App\Console\Commands\CalendarioIa;
use App\Models\Post;
use App\Models\PostRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class PainelController extends Controller
{
    /** Secções do menu: chave => [título, estados mostrados]. */
    public const SECTIONS = [
        'fila' => ['Para aprovar', [Post::PENDING, Post::REVISING]],
        'agendados' => ['Agendados', [Post::APPROVED]],
        'publicados' => ['Publicados', [Post::PUBLISHED]],
        'rejeitados' => ['Rejeitados', [Post::REJECTED]],
        'ideias' => ['Ideias e em criação', [Post::IDEA, Post::CREATING]],
    ];

    public function index(Request $request): View
    {
        // "Pedidos de posts" juntou-se a "Ideias e em criação" (24 nov): os links antigos abrem lá.
        $ver = $request->query('ver') === 'pedidos' ? 'ideias' : $request->query('ver', 'fila');
        if (! isset(self::SECTIONS[$ver])) {
            $ver = 'fila';
        }

        $sections = array_map(fn ($s) => ['title' => $s[0]], self::SECTIONS);

        $posts = collect();
        // Em "Ideias e em criação", no topo: os pedidos que a IA está a fazer e os que falharam (com "Tentar outra vez").
        // Só os que ainda não têm post ("Pedir um post à IA"): os de uma ideia já aparecem no cartão da ideia.
        $requests = $ver === 'ideias'
            ? PostRequest::with('user')->whereNull('post_id')->whereIn('status', ['new', 'in_progress', 'failed'])->latest()->limit(30)->get()
            : collect();
        $antigos = in_array($ver, ['publicados', 'rejeitados'], true); // crescem sempre: vão por páginas
        $query = Post::query()
            // só as últimas 8 entradas do histórico de cada post (e o total, para o "Histórico (N)")
            ->with(['reviews' => fn ($q) => $q->with('user')->limit(8)])
            ->withCount('reviews')
            // a IA está a fazer / falhou: perguntado de uma vez, não um pedido por cartão
            ->withExists(['pedidos as ia_a_fazer' => fn ($q) => $q->whereIn('status', ['new', 'in_progress'])])
            ->withExists(['pedidos as ia_falhou' => fn ($q) => $q->where('status', 'failed')])
            ->whereIn('status', self::SECTIONS[$ver][1])
            ->orderByRaw('scheduled_at is null')
            ->orderBy('scheduled_at', $antigos ? 'desc' : 'asc');
        $posts = $antigos ? $query->simplePaginate(20)->withQueryString() : $query->get();

        // Resumo no topo da fila: o que espera por si, o que vai sair a seguir e se algo correu mal.
        $resumo = null;
        if ($ver === 'fila') {
            $n = Post::contagens();
            $resumo = [
                'para_aprovar' => ($n[Post::PENDING] ?? 0),
                'atrasados' => Post::where('status', Post::PENDING)->where('scheduled_at', '<', now()->addHour())->count(),
                'agendados' => ($n[Post::APPROVED] ?? 0),
                'proximo' => Post::where('status', Post::APPROVED)->where('scheduled_at', '>=', now())->orderBy('scheduled_at')->first(),
                'falhas' => Post::where('status', Post::APPROVED)->whereNotNull('publish_error')->count(),
                'ideias' => ($n[Post::IDEA] ?? 0) + ($n[Post::CREATING] ?? 0),
                'meta_ligada' => app(\App\Services\MetaPublisher::class)->configured(),
            ];
        }

        return view('painel.index', [
            'resumo' => $resumo,
            'ver' => $ver,
            'sections' => $sections,
            'posts' => $posts,
            'requests' => $requests,
            'pronto' => $request->integer('pronto') ? Post::find($request->integer('pronto')) : null,
        ]);
    }

    /**
     * Para a página se atualizar sozinha quando a IA acaba: estado dos posts e pedidos que a página
     * está à espera (?posts=1,2&pedidos=3), e se ainda está a propor as ideias da semana.
     */
    public function estadoIa(Request $request): JsonResponse
    {
        $ids = fn (string $campo) => array_slice(array_filter(array_map('intval', explode(',', (string) $request->query($campo)))), 0, 50);

        return response()->json([
            'posts' => Post::whereIn('id', $ids('posts'))->get(['id', 'status', 'change_request'])
                ->mapWithKeys(fn ($p) => [$p->id => ['estado' => $p->status, 'troca' => filled($p->change_request)]]),
            'pedidos' => PostRequest::whereIn('id', $ids('pedidos'))->get(['id', 'status', 'post_id'])
                ->mapWithKeys(fn ($r) => [$r->id => ['estado' => $r->status, 'post' => $r->post_id]]),
            'propor' => Cache::has(CalendarioIa::PEDIDO_PROPOR) || Cache::has(CalendarioIa::A_PROPOR),
        ]);
    }
}
