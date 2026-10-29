<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostRequest;
use Illuminate\Http\Request;
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
        ]);
    }
}
