<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Decisões de uma pessoa sobre um post: aprovar, rejeitar, pedir alteração, desfazer. */
class PostDecisionController extends Controller
{
    public function approve(Request $request, Post $post): RedirectResponse
    {
        $this->mustBe($post, [Post::PENDING]);
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

        return back()->with('ok', 'Pedido de troca de tema enviado ao Claude.');
    }

    public function discard(Request $request, Post $post): RedirectResponse
    {
        $this->mustBe($post, [Post::IDEA, Post::CREATING]);
        $post->decide('discarded', Post::REJECTED, $request->user());

        return back()->with('ok', "A ideia “{$post->title}” foi descartada.");
    }

    private function mustBe(Post $post, array $statuses): void
    {
        abort_unless(in_array($post->status, $statuses, true), 409,
            'Este post já mudou de estado. Atualize a página.');
    }
}
