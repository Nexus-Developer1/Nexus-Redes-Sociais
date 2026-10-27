<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Envia um post para a fila de aprovação. Nunca aprova nem publica:
 * o post fica sempre "À espera de aprovação".
 *
 * Exemplo:
 *   php artisan post:submeter --titulo="Dica: backup" --imagem=../posts/dica-backup/post-nexus-backup.png \
 *     --legenda-ficheiro=legenda.txt --redes=Instagram,Facebook --quando="2026-10-26 10:00"
 */
class SubmeterPost extends Command
{
    protected $signature = 'post:submeter
        {--titulo= : Título interno do post}
        {--imagem= : Caminho do PNG/JPG final}
        {--alt= : Texto alternativo da imagem}
        {--legenda= : Legenda do post}
        {--legenda-ficheiro= : Ficheiro de texto com a legenda (alternativa a --legenda)}
        {--redes=Instagram,Facebook : Redes, separadas por vírgulas}
        {--quando= : Data e hora, ex.: "2026-10-26 10:00"}
        {--origem= : De onde veio a ideia}
        {--post= : ID de um post existente (nova versão após pedido de alteração, ou ideia já criada)}
        {--pedido= : ID do pedido do painel que este post responde}';

    protected $description = 'Envia um post para a fila de aprovação do painel';

    public function handle(): int
    {
        $post = $this->option('post') ? Post::find($this->option('post')) : new Post;
        if (! $post) {
            $this->error('Post não encontrado.');

            return self::FAILURE;
        }
        if ($post->exists && in_array($post->status, [Post::APPROVED, Post::PUBLISHED], true)) {
            $this->error('Este post já foi aprovado. Peça para desfazer a aprovação no painel antes de o alterar.');

            return self::FAILURE;
        }

        $caption = $this->option('legenda');
        if ($file = $this->option('legenda-ficheiro')) {
            if (! is_file($file)) {
                $this->error("Ficheiro de legenda não existe: {$file}");

                return self::FAILURE;
            }
            $caption = trim(file_get_contents($file));
        }

        $title = $this->option('titulo') ?: $post->title;
        if (! $title) {
            $this->error('Falta --titulo.');

            return self::FAILURE;
        }

        if ($image = $this->option('imagem')) {
            $ext = strtolower(pathinfo($image, PATHINFO_EXTENSION));
            if (! is_file($image) || ! in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                $this->error("Imagem inválida: {$image}");

                return self::FAILURE;
            }
            // Nome único para o browser não mostrar uma versão antiga.
            $name = Str::slug($title).'-'.now()->format('YmdHis').'.'.$ext;
            File::ensureDirectoryExists(public_path('imagens-posts'));
            File::copy($image, public_path('imagens-posts/'.$name));
            $post->image_path = 'imagens-posts/'.$name;
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
        $post->caption = $caption ?? $post->caption;
        $post->image_alt = $this->option('alt') ?? $post->image_alt;
        $post->networks = $networks;
        $post->source = $this->option('origem') ?? $post->source;
        if ($when = $this->option('quando')) {
            $post->scheduled_at = Carbon::parse($when);
        }
        $post->status = Post::PENDING;
        $post->save();

        if ($id = $this->option('pedido')) {
            PostRequest::whereKey($id)->update(['status' => 'done']);
        }

        $this->info("Post #{$post->id} enviado para aprovação: {$post->title}");

        return self::SUCCESS;
    }
}
