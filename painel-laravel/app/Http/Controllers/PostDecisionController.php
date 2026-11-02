<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Decisões de uma pessoa sobre um post: aprovar, rejeitar, pedir alteração, desfazer. */
class PostDecisionController extends Controller
{
    public function approve(Request $request, Post $post): RedirectResponse
    {
        $this->mustBe($post, [Post::PENDING]);
        // Só se aprova a versão que a pessoa viu: se o post mudou entretanto, mostra-se a nova.
        if ($request->filled('versao') && $request->input('versao') !== (string) $post->updated_at?->timestamp) {
            return back()->with('erro', "“{$post->title}” foi alterado entretanto. Reveja a versão nova antes de aprovar.");
        }
        $post->decide('approved', Post::APPROVED, $request->user());

        return back()->with('ok', "“{$post->title}” foi aprovado e agendado.");
    }

    public function reject(Request $request, Post $post): RedirectResponse
    {
        $this->mustBe($post, [Post::PENDING]);
        $post->decide('rejected', Post::REJECTED, $request->user());

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

        $post->decide('change_requested', Post::REVISING, $request->user(), $data['comment']);

        return back()->with('ok', 'Pedido de alteração enviado ao Claude.');
    }

    public function undo(Request $request, Post $post): RedirectResponse
    {
        // Um post já publicado não pode voltar atrás.
        $this->mustBe($post, [Post::APPROVED, Post::REJECTED, Post::REVISING]);
        $post->decide('undone', Post::PENDING, $request->user());

        return back()->with('ok', "“{$post->title}” voltou à fila para aprovar.");
    }

    /** O aprovador pede outro tema para esta data. O Claude troca a ideia (post:ideia --post=ID). */
    public function swapIdea(Request $request, Post $post): RedirectResponse
    {
        $this->mustBe($post, [Post::IDEA, Post::CREATING]);
        $data = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ], [
            'comment.required' => 'Escreva que tema prefere (ou porque quer trocar).',
        ]);

        $post->decide('idea_swap', Post::IDEA, $request->user(), $data['comment']);

        return back()->with('ok', app(\App\Services\PostWriter::class)->configurado()
            ? 'A IA vai propor outro tema para esta data dentro de 1 a 2 minutos.'
            : 'Pedido de troca de tema enviado ao Claude.');
    }

    public function discard(Request $request, Post $post): RedirectResponse
    {
        $this->mustBe($post, [Post::IDEA, Post::CREATING]);
        $post->decide('discarded', Post::REJECTED, $request->user());

        return back()->with('ok', "A ideia “{$post->title}” foi descartada.");
    }

    /** "A IA faz este post": a ideia vira um pedido para a IA, que faz o post nesse mesmo cartão (mantém a data). */
    public function aiMake(Request $request, Post $post): RedirectResponse
    {
        $this->mustBe($post, [Post::IDEA, Post::CREATING]);
        if (PostRequest::where('post_id', $post->id)->whereIn('status', ['new', 'in_progress'])->exists()) {
            return back()->with('ok', 'A IA já está a fazer este post.');
        }

        PostRequest::paraIdeia($post, $request->user()->id);
        $post->decide('ai_requested', Post::CREATING, $request->user());

        return back()->with('ok', "A IA está a fazer “{$post->title}”: aparece em “Para aprovar” dentro de 1 a 2 minutos.");
    }

    private function mustBe(Post $post, array $statuses): void
    {
        abort_unless(in_array($post->status, $statuses, true), 409,
            'Este post já mudou de estado. Atualize a página.');
    }
}
