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
    private const SECTIONS = [
        'fila' => ['Para aprovar', [Post::PENDING, Post::REVISING]],
        'agendados' => ['Agendados', [Post::APPROVED]],
        'publicados' => ['Publicados', [Post::PUBLISHED]],
        'rejeitados' => ['Rejeitados', [Post::REJECTED]],
        'ideias' => ['Ideias e em criação', [Post::IDEA, Post::CREATING]],
    ];

    public function index(Request $request): View
    {
        $ver = $request->query('ver', 'fila');
        if ($ver !== 'pedidos' && ! isset(self::SECTIONS[$ver])) {
            $ver = 'fila';
        }

        $counts = Post::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $sections = [];
        foreach (self::SECTIONS as $key => [$title, $statuses]) {
            $sections[$key] = [
                'title' => $title,
                'count' => collect($statuses)->sum(fn ($s) => $counts[$s] ?? 0),
            ];
        }
        $sections['pedidos'] = [
            'title' => 'Pedidos de posts',
            'count' => PostRequest::where('status', '!=', 'done')->count(),
        ];

        $posts = collect();
        $requests = collect();
        if ($ver === 'pedidos') {
            $requests = PostRequest::with(['user', 'post'])->latest()->get();
        } else {
            $posts = Post::with(['reviews.user', 'decider'])
                ->whereIn('status', self::SECTIONS[$ver][1])
                ->orderByRaw('scheduled_at is null')
                ->orderBy('scheduled_at', in_array($ver, ['publicados', 'rejeitados']) ? 'desc' : 'asc')
                ->get();
        }

        $upcoming = Post::whereIn('status', [Post::IDEA, Post::CREATING, Post::APPROVED])
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->limit(5)
            ->get();

        return view('painel.index', [
            'ver' => $ver,
            'sections' => $sections,
            'posts' => $posts,
            'requests' => $requests,
            'upcoming' => $upcoming,
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
