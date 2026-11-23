<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\MetaPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Publica os posts APROVADOS cuja hora já chegou. Corre sozinho a cada 5 minutos (routes/console.php).
 * Nunca publica nada que não tenha sido aprovado por uma pessoa.
 */
class PublicarPosts extends Command
{
    protected $signature = 'post:publicar {--simular : Mostra o que seria publicado, sem publicar}';

    protected $description = 'Publica no Facebook e Instagram os posts aprovados cuja hora chegou';

    /** Um post aprovado só sai sozinho até estas horas depois da hora marcada. Mais tarde, fica à espera de nova data. */
    public const MAX_ATRASO_HORAS = 6;

    public function handle(MetaPublisher $meta): int
    {
        // Uma publicação de cada vez (o cron e alguém a correr o comando à mão nunca publicam o mesmo post duas vezes).
        $trinco = Cache::lock('post:publicar', 15 * 60);
        if (! $trinco->get()) {
            $this->line('Já há uma publicação a decorrer.');

            return self::SUCCESS;
        }
        try {
            return $this->publicarTudo($meta);
        } finally {
            $trinco->release();
        }
    }

    private function publicarTudo(MetaPublisher $meta): int
    {
        if (! $this->option('simular')) {
            $this->marcarAtrasados();
        }

        $due = Post::where('status', Post::APPROVED)
            ->where('scheduled_at', '>=', now()->subHours(self::MAX_ATRASO_HORAS))
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->where('publish_attempts', '<', Post::MAX_PUBLISH_ATTEMPTS)
            ->orderBy('scheduled_at')
            ->get()
            ->filter(fn (Post $p) => array_intersect(Post::AUTO_NETWORKS, $p->networks ?? []));

        if ($due->isEmpty()) {
            $this->line('Nada para publicar.');

            return self::SUCCESS;
        }

        if ($this->option('simular')) {
            foreach ($due as $post) {
                $this->line("Seria publicado: #{$post->id} {$post->title} → "
                    .implode(', ', array_intersect(Post::AUTO_NETWORKS, $post->networks)));
            }

            return self::SUCCESS;
        }

        if (! $meta->configured()) {
            $this->warn('Ligação à Meta ainda não configurada (META_PAGE_ID / META_PAGE_TOKEN). Nada publicado.');

            return self::SUCCESS;
        }

        foreach ($due as $post) {
            $this->publish($post, $meta);
        }

        return self::SUCCESS;
    }

    /**
     * Posts aprovados cuja hora passou há mais de MAX_ATRASO_HORAS (ex.: um aviso "amanhã estamos fechados" de há
     * semanas) não saem sozinhos: ficam com uma nota no painel para a pessoa escolher outra data.
     */
    private function marcarAtrasados(): void
    {
        $nota = 'Não foi publicado: a hora marcada passou há mais de '.self::MAX_ATRASO_HORAS.' horas. Mude a data para publicar.';
        $atrasados = Post::where('status', Post::APPROVED)
            ->where('scheduled_at', '<', now()->subHours(self::MAX_ATRASO_HORAS))
            ->where(fn ($q) => $q->whereNull('publish_error')->orWhere('publish_error', '!=', $nota))
            ->get();
        foreach ($atrasados as $post) {
            $post->forceFill(['publish_error' => $nota])->save();
            $post->reviews()->create(['action' => 'publish_failed', 'comment' => $nota]);
            $this->warn("#{$post->id} atrasado: não publicado.");
        }
    }

    private function publish(Post $post, MetaPublisher $meta): void
    {
        // Confirmar de novo: a pessoa pode ter desfeito a aprovação entretanto.
        $post->refresh();
        if ($post->status !== Post::APPROVED) {
            return;
        }

        $targets = [
            'Facebook' => ['facebook_post_id', fn () => $meta->publishFacebook($post)],
            'Instagram' => ['instagram_media_id', fn () => $meta->publishInstagram($post)],
            'Stories' => ['instagram_story_id', fn () => $meta->publishStory($post)],
        ];

        $done = [];
        foreach ($targets as $network => [$column, $publish]) {
            if (! in_array($network, $post->networks, true)) {
                continue;
            }
            if ($post->{$column}) { // já publicado numa tentativa anterior
                $done[] = $network;

                continue;
            }

            try {
                $post->{$column} = $publish();
                $post->save();
                $done[] = $network;
                $this->info("#{$post->id} publicado no {$network}.");
            } catch (Throwable $e) {
                report($e);
                $post->publish_attempts++;
                $post->publish_error = "{$network}: {$e->getMessage()}";
                $post->save();
                $post->reviews()->create(['action' => 'publish_failed', 'comment' => $post->publish_error]);
                $this->error("#{$post->id} falhou no {$network}: {$e->getMessage()}");

                return; // tenta outra vez na próxima execução
            }
        }

        $note = 'Publicado em: '.implode(', ', $done).'.';
        if (in_array('LinkedIn', $post->networks, true)) {
            $note .= ' O LinkedIn ainda tem de ser publicado à mão.';
        }

        $post->status = Post::PUBLISHED;
        $post->published_at = now();
        $post->publish_error = null;
        $post->save();
        $post->reviews()->create(['action' => 'published', 'comment' => $note]);
    }
}
