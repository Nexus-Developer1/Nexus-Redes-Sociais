<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostRequest;
use Illuminate\Console\Command;

/** Mostra ao Claude o que há para fazer: pedidos novos, alterações pedidas e ideias por criar. */
class TrabalhoPendente extends Command
{
    protected $signature = 'post:pendentes {--json : Saída em JSON}';

    protected $description = 'Lista pedidos de posts e alterações à espera do Claude';

    public function handle(): int
    {
        $requests = PostRequest::where('status', '!=', 'done')->oldest()->get(['id', 'topic', 'status', 'created_at']);
        $revisions = Post::where('status', Post::REVISING)->get(['id', 'title', 'change_request', 'scheduled_at']);
        $ideas = Post::whereIn('status', [Post::IDEA, Post::CREATING])->orderBy('scheduled_at')->get(['id', 'title', 'status', 'scheduled_at', 'change_request']);

        if ($this->option('json')) {
            $this->line(json_encode(compact('requests', 'revisions', 'ideas'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Pedidos de posts');
        $requests->isEmpty() ? $this->line('  (nenhum)') : $this->table(['ID', 'Tema', 'Estado'],
            $requests->map(fn ($r) => [$r->id, $r->topic, $r->status]));

        $this->info('Alterações pedidas');
        $revisions->isEmpty() ? $this->line('  (nenhuma)') : $this->table(['Post', 'Título', 'O que mudar'],
            $revisions->map(fn ($p) => [$p->id, $p->title, $p->change_request]));

        $this->info('Ideias por criar');
        $ideas->isEmpty() ? $this->line('  (nenhuma)') : $this->table(['Post', 'Título', 'Estado', 'Data', 'Trocar tema?'],
            $ideas->map(fn ($p) => [$p->id, $p->title, $p->status, $p->scheduled_at?->format('Y-m-d H:i'), $p->change_request ?? '']));

        return self::SUCCESS;
    }
}
