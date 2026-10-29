<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostRequest;
use App\Models\User;
use App\Notifications\PostParaAprovar;
use App\Services\Pexels;
use App\Services\PostRenderer;
use App\Services\PostWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Trata sozinho do trabalho que antes esperava pelo Claude (corre a cada minuto no servidor):
 *  - pedidos novos de "Pedir um post"  → a IA escreve o post, o servidor gera a imagem, vai para "Para aprovar";
 *  - "Pedir alteração" em posts feitos com os modelos → a IA refaz os textos e o post volta à fila.
 * Nunca aprova nem publica. Sem IA configurada não faz nada.
 */
class TratarPedidos extends Command
{
    protected $signature = 'post:tratar-pedidos {--max=3 : Máximo de pedidos/alterações por execução}';

    protected $description = 'A IA cria os posts pedidos no painel e refaz os que têm alteração pedida';

    public function handle(PostWriter $escritor, PostRenderer $renderer): int
    {
        if (! $escritor->configurado()) {
            $this->line('IA não configurada (IA_CHAVE): nada a fazer.');

            return self::SUCCESS;
        }

        $restam = (int) $this->option('max');

        foreach (PostRequest::where('status', 'new')->oldest()->limit($restam)->get() as $pedido) {
            $restam--;
            $this->criar($pedido, $escritor, $renderer);
        }

        if ($restam > 0) {
            $alteracoes = Post::where('status', Post::REVISING)
                ->whereNotNull('design')->whereNotNull('change_request')
                ->oldest('updated_at')->limit($restam)->get();
            foreach ($alteracoes as $post) {
                $this->rever($post, $escritor, $renderer);
            }
        }

        return self::SUCCESS;
    }

    private function criar(PostRequest $pedido, PostWriter $escritor, PostRenderer $renderer): void
    {
        $pedido->update(['status' => 'in_progress', 'error' => null]);
        $post = null;

        try {
            $r = $escritor->criar($pedido->topic, $pedido->publish_at);

            $post = Post::create([
                'title' => $r['titulo_interno'],
                'caption' => $r['legenda'],
                'networks' => $r['redes'],
                'scheduled_at' => $r['publicar_em'],
                'status' => Post::CREATING,
                'source' => 'Pedido de '.($pedido->user?->name ?? 'alguém').': “'.$pedido->topic.'”',
                'design' => ['formato' => $r['design']['formato'] ?? '4:5'],
            ]);
            $design = $this->comFoto($post, $r['design'], $r['pesquisa_imagem'] ?? '');
            $this->montar($post, $design, $renderer);
            $this->paraAprovacao($post, 'auto_created');

            $pedido->update(['status' => 'done', 'post_id' => $post->id]);
            $this->info("Pedido #{$pedido->id} → post #{$post->id}: {$post->title}");
        } catch (Throwable $e) {
            report($e);
            $post?->delete();
            if (str_contains($e->getMessage(), 'erro 429')) {
                // Limite do plano gratuito: o pedido volta à fila e tenta-se de novo no minuto seguinte.
                $pedido->update(['status' => 'new', 'error' => 'Limite da IA atingido; volta a tentar sozinho.']);
            } else {
                $pedido->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            }
            $this->error("Pedido #{$pedido->id} falhou: {$e->getMessage()}");
        }
    }

    private function rever(Post $post, PostWriter $escritor, PostRenderer $renderer): void
    {
        // Se falhou há pouco com este mesmo pedido, espera uma hora antes de tentar de novo.
        $chave = 'ia-falhou:post:'.$post->id.':'.md5((string) $post->change_request);
        if (Cache::has($chave)) {
            return;
        }

        try {
            $r = $escritor->rever($post, $post->change_request);
            $post->fill([
                'title' => $r['titulo_interno'],
                'caption' => $r['legenda'],
                'networks' => $r['redes'],
                'scheduled_at' => $r['publicar_em'],
            ]);
            // As imagens que alguém pôs no post (fundo, foto, parceiro) mantêm-se.
            $design = $r['design'] + array_intersect_key($post->design ?? [], array_flip(['foto', 'fundo', 'produto', 'parceiro', 'enquadramento', 'tom']));
            $this->montar($post, $design, $renderer);
            $this->paraAprovacao($post, 'auto_revised');
            $this->info("Post #{$post->id} refeito: {$post->title}");
        } catch (Throwable $e) {
            report($e);
            Cache::put($chave, true, now()->addHour());
            $this->error("Post #{$post->id}: a alteração falhou: {$e->getMessage()}");
        }
    }

    /**
     * Com o Pexels ligado, procura uma foto de fundo para o tema. Se falhar, o post segue sem foto
     * (fundo verde da marca); nunca impede o post de chegar à fila.
     */
    private function comFoto(Post $post, array $design, string $pesquisa): array
    {
        $pexels = app(Pexels::class);
        if ($pesquisa === '' || ! $pexels->configurado()) {
            return $design;
        }

        try {
            $fotos = $pexels->procurar($pesquisa, $design['formato'] ?? '4:5', 5);
            if (! $fotos) {
                return $design;
            }
            $foto = $pexels->descarregar($fotos[0]['id'], $post);
            $design['foto'] = $foto['nome'];
            $design['tom'] = 'suave';
            unset($design['icone']); // o ícone tapava a foto
            $post->source = trim($post->source.' · Foto: '.$foto['fotografo'].' (Pexels)');
        } catch (Throwable $e) {
            report($e);
            $this->warn("Post #{$post->id}: sem foto do Pexels ({$e->getMessage()}).");
        }

        return $design;
    }

    private function montar(Post $post, array $design, PostRenderer $renderer): void
    {
        $post->design = PostRenderer::limparDesign($design, $post);
        $post->image_alt = trim(str_replace(['*', "\n"], ['', ' '], $post->design['titulo'] ?? $post->title));
        $post->save();

        $imagem = $renderer->render($post);
        $post->image_path = $imagem['path'];
        $post->save();
    }

    private function paraAprovacao(Post $post, string $acao): void
    {
        $post->status = Post::PENDING;
        $post->change_request = null;
        $post->save();
        $post->reviews()->create(['user_id' => null, 'action' => $acao]);

        try {
            Notification::send(User::all(), new PostParaAprovar($post));
        } catch (Throwable $e) {
            report($e); // o post fica na fila na mesma
        }
    }
}
