<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Põe uma ideia de post no calendário (ou troca uma ideia que o aprovador pediu para trocar).
 * Uma ideia não é publicada: o Claude cria o post mais tarde e envia-o para aprovação.
 *
 * Exemplo:
 *   php artisan post:ideia --titulo="Dica: phishing" --quando="2026-11-10 10:00" --tipo=dica --redes=Instagram,Facebook,LinkedIn
 */
class CriarIdeia extends Command
{
    protected $signature = 'post:ideia
        {--titulo= : Tema do post}
        {--quando= : Data e hora, ex.: "2026-11-10 10:00"}
        {--tipo= : produto, dica, evento ou aviso}
        {--redes=Instagram,Facebook : Redes, separadas por vírgulas}
        {--origem= : Porquê este tema / notas para criar o post}
        {--post= : ID de uma ideia existente a substituir (ex.: depois de "Trocar tema")}';

    protected $description = 'Põe uma ideia de post no calendário do painel';

    public const TIPOS = ['produto', 'dica', 'evento', 'aviso'];

    public function handle(): int
    {
        $post = $this->option('post') ? Post::find($this->option('post')) : new Post;
        if (! $post) {
            $this->error('Post não encontrado.');

            return self::FAILURE;
        }
        if ($post->exists && ! in_array($post->status, [Post::IDEA, Post::CREATING], true)) {
            $this->error('Esse post já não é uma ideia.');

            return self::FAILURE;
        }

        $title = $this->option('titulo') ?: $post->title;
        if (! $title) {
            $this->error('Falta --titulo.');

            return self::FAILURE;
        }

        $tipo = $this->option('tipo');
        if ($tipo && ! in_array($tipo, self::TIPOS, true)) {
            $this->error('Tipo inválido. Use: '.implode(', ', self::TIPOS));

            return self::FAILURE;
        }

        $networks = array_values(array_intersect(
            Post::NETWORKS,
            array_map('trim', explode(',', $this->option('redes')))
        ));
        if (! $networks) {
            $this->error('Redes inválidas. Use: '.implode(', ', Post::NETWORKS));

            return self::FAILURE;
        }

        $post->title = $title;
        $post->networks = $networks;
        if ($when = $this->option('quando')) {
            $post->scheduled_at = Carbon::parse($when);
        }
        $notes = $this->option('origem');
        if ($tipo || $notes) {
            $post->source = trim(($tipo ? 'Tipo: '.$tipo.'. ' : '').($notes ?? ''));
        }
        $post->status = Post::IDEA;
        $post->change_request = null;
        $post->save();

        $this->info("Ideia #{$post->id} no calendário: {$post->title} ({$post->whenLabel()})");

        return self::SUCCESS;
    }
}
