<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Decisões de uma pessoa sobre um post: aprovar, rejeitar, pedir alteração, desfazer.
 * Cada decisão só é gravada se o post ainda estiver no estado em que a pessoa o viu (ver Post::decide):
 * a IA trabalha ao mesmo tempo e nunca pode passar por cima de uma decisão, nem o contrário.
 */
class PostDecisionController extends Controller
{
    public function approve(Request $request, Post $post): RedirectResponse
    {
        $this->mustBe($post, [Post::PENDING]);
        // Só se aprova a versão que a pessoa viu: se o post mudou entretanto, mostra-se a nova.
        if ($request->filled('versao') && $request->input('versao') !== (string) $post->updated_at?->timestamp) {
            return back()->with('erro', "“{$post->title}” foi alterado entretanto. Reveja a versão nova antes de aprovar.");
        }
        if (! $post->image_path) {
            return back()->with('erro', "“{$post->title}” ainda não tem imagem. Gere a imagem em “Editar” antes de aprovar.");
        }
        if (! $post->scheduled_at) {
            return back()->with('erro', "“{$post->title}” não tem data de publicação. Escolha a data antes de aprovar.");
        }
        if ($post->scheduled_at->lt(now()->subHours(\App\Console\Commands\PublicarPosts::MAX_ATRASO_HORAS))) {
            return back()->with('erro', "A data de “{$post->title}” já passou ({$post->whenLabel()}). Mude a data antes de aprovar.");
        }
        $this->decidir($post, [Post::PENDING], 'approved', Post::APPROVED, $request);

        return back()->with('ok', $post->scheduled_at->isPast()
            ? "“{$post->title}” foi aprovado. A data já passou: sai na próxima publicação automática."
            : "“{$post->title}” foi aprovado e agendado para {$post->whenLabel()}.");
    }

    public function reject(Request $request, Post $post): RedirectResponse
    {
        $this->decidir($post, [Post::PENDING], 'rejected', Post::REJECTED, $request);

        return back()->with('ok', "“{$post->title}” foi rejeitado.");
    }

    public function requestChange(Request $request, Post $post): RedirectResponse
    {
        $this->mustBe($post, [Post::PENDING]);
        $data = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ], [
            'comment.required' => 'Escreva o que quer mudar.',
        ]);

        $this->decidir($post, [Post::PENDING], 'change_requested', Post::REVISING, $request, $data['comment']);

        return back()->with('ok', app(\App\Services\PostWriter::class)->configurado() && $post->design
            ? 'Pedido de alteração enviado: a IA refaz o post dentro de 1 a 2 minutos.'
            : 'Pedido de alteração enviado ao Claude.');
    }

    public function undo(Request $request, Post $post): RedirectResponse
    {
        // Um post publicado (mesmo que só numa rede) não volta atrás: a rede já tem essa versão.
        if ($post->publicadoAlgures()) {
            return back()->with('erro', "“{$post->title}” já saiu numa rede social: já não pode ser desfeito.");
        }
        $this->decidir($post, [Post::APPROVED, Post::REJECTED, Post::REVISING], 'undone', Post::PENDING, $request);
        // Uma nova aprovação começa as tentativas de publicação do zero.
        $post->forceFill(['publish_attempts' => 0, 'publish_error' => null])->save();

        return back()->with('ok', "“{$post->title}” voltou à fila para aprovar.");
    }

    /** Depois de a publicação falhar 3 vezes, a pessoa pode mandar tentar de novo (ex.: depois de corrigir a ligação). */
    public function retryPublish(Request $request, Post $post): RedirectResponse
    {
        $this->mustBe($post, [Post::APPROVED]);
        $post->forceFill(['publish_attempts' => 0, 'publish_error' => null])->save();
        $post->reviews()->create(['user_id' => $request->user()->id, 'action' => 'publish_retry']);

        return back()->with('ok', "Vai tentar publicar “{$post->title}” outra vez nos próximos 5 minutos.");
    }

    /** O aprovador pede outro tema para esta data. A IA (ou o Claude) troca a ideia. */
    public function swapIdea(Request $request, Post $post): RedirectResponse
    {
        $this->mustBeIdea($post);
        $data = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ], [
            'comment.required' => 'Escreva que tema prefere (ou porque quer trocar).',
        ]);

        $this->decidir($post, [Post::IDEA, Post::CREATING], 'idea_swap', Post::IDEA, $request, $data['comment']);

        return back()->with('ok', app(\App\Services\PostWriter::class)->configurado()
            ? 'A IA vai propor outro tema para esta data dentro de 1 a 2 minutos.'
            : 'Pedido de troca de tema enviado ao Claude.');
    }

    public function discard(Request $request, Post $post): RedirectResponse
    {
        $this->mustBeIdea($post);
        $this->decidir($post, [Post::IDEA, Post::CREATING], 'discarded', Post::REJECTED, $request);

        return back()->with('ok', "A ideia “{$post->title}” foi descartada.");
    }

    /** "A IA faz este post": a ideia vira um pedido para a IA, que faz o post nesse mesmo cartão (mantém a data). */
    public function aiMake(Request $request, Post $post): RedirectResponse
    {
        $this->mustBeIdea($post);
        if (PostRequest::where('post_id', $post->id)->whereIn('status', ['new', 'in_progress'])->exists()) {
            return back()->with('ok', 'A IA já está a fazer este post.');
        }

        PostRequest::paraIdeia($post, $request->user()->id);
        $post->decide('ai_requested', Post::CREATING, $request->user());

        return back()->with('ok', "A IA está a fazer “{$post->title}”: aparece em “Para aprovar” dentro de 1 a 2 minutos.");
    }

    /** Muda o estado só se o post ainda estiver num destes estados; senão a pessoa vê que mudou entretanto. */
    private function decidir(Post $post, array $de, string $acao, string $para, Request $request, ?string $comentario = null): void
    {
        $this->mustBe($post, $de);
        abort_unless($post->decide($acao, $para, $request->user(), $comentario, $de), 409,
            'Este post mudou entretanto (a IA ou outra pessoa mexeu nele). Atualize a página.');
    }

    /** Ideias (ou ideias que a IA está a fazer). Um post que a IA está a refazer não é uma ideia. */
    private function mustBeIdea(Post $post): void
    {
        $this->mustBe($post, [Post::IDEA, Post::CREATING]);
        abort_if($post->aRefazer(), 409, 'A IA está a refazer este post. Atualize a página daqui a um minuto.');
    }

    private function mustBe(Post $post, array $statuses): void
    {
        abort_unless(in_array($post->status, $statuses, true), 409,
            'Este post já mudou de estado. Atualize a página.');
    }
}
