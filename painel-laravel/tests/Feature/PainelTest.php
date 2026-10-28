<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostRequest;
use App\Models\User;
use App\Notifications\PostParaAprovar;
use Illuminate\Support\Facades\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PainelTest extends TestCase
{
    use RefreshDatabase;

    private function novoPost(array $attrs = []): Post
    {
        return Post::create(array_merge([
            'title' => 'Dica de backup',
            'caption' => 'Regra 3-2-1',
            'networks' => ['Instagram'],
            'scheduled_at' => now()->addDay(),
            'status' => Post::PENDING,
        ], $attrs));
    }

    public function test_visitante_vai_para_o_login(): void
    {
        $this->get('/')->assertRedirect('/entrar');
        $this->post('/posts/1/aprovar')->assertRedirect('/entrar');
    }

    public function test_login_e_painel(): void
    {
        $user = User::factory()->create(['password' => 'segredo123']);
        $this->post('/entrar', ['email' => $user->email, 'password' => 'errada'])->assertSessionHasErrors('email');
        $this->post('/entrar', ['email' => $user->email, 'password' => 'segredo123'])->assertRedirect('/');

        $this->novoPost();
        $this->get('/')->assertOk()->assertSee('Dica de backup')->assertSee('Aprovar e agendar');
    }

    public function test_aprovar_regista_quem_aprovou_e_pode_desfazer(): void
    {
        $user = User::factory()->create();
        $post = $this->novoPost();

        $this->actingAs($user)->post("/posts/{$post->id}/aprovar")->assertRedirect();
        $post->refresh();
        $this->assertSame(Post::APPROVED, $post->status);
        $this->assertSame($user->id, $post->decided_by);
        $this->assertDatabaseHas('post_reviews', ['post_id' => $post->id, 'user_id' => $user->id, 'action' => 'approved']);

        // Não se pode aprovar duas vezes.
        $this->actingAs($user)->post("/posts/{$post->id}/aprovar")->assertStatus(409);

        $this->actingAs($user)->post("/posts/{$post->id}/desfazer")->assertRedirect();
        $this->assertSame(Post::PENDING, $post->fresh()->status);
    }

    public function test_pedir_alteracao_exige_comentario(): void
    {
        $user = User::factory()->create();
        $post = $this->novoPost();

        $this->actingAs($user)->post("/posts/{$post->id}/alterar", ['comment' => ''])->assertSessionHasErrors('comment');
        $this->actingAs($user)->post("/posts/{$post->id}/alterar", ['comment' => 'Fundo mais claro'])->assertRedirect();

        $post->refresh();
        $this->assertSame(Post::REVISING, $post->status);
        $this->assertSame('Fundo mais claro', $post->change_request);
    }

    public function test_rejeitar_e_post_publicado_nao_volta_atras(): void
    {
        $user = User::factory()->create();
        $post = $this->novoPost();
        $this->actingAs($user)->post("/posts/{$post->id}/rejeitar")->assertRedirect();
        $this->assertSame(Post::REJECTED, $post->fresh()->status);

        $publicado = $this->novoPost(['status' => Post::PUBLISHED]);
        $this->actingAs($user)->post("/posts/{$publicado->id}/desfazer")->assertStatus(409);
    }

    public function test_pedir_um_post(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/pedidos', ['topic' => 'Feriado de 1 de dezembro'])->assertRedirect();
        $this->assertDatabaseHas('post_requests', ['topic' => 'Feriado de 1 de dezembro', 'status' => 'new']);
        $this->actingAs($user)->get('/?ver=pedidos')->assertOk()->assertSee('Feriado de 1 de dezembro');
    }

    public function test_comando_submeter_poe_na_fila_sem_aprovar(): void
    {
        $pedido = PostRequest::create(['topic' => 'Natal']);
        $this->artisan('post:submeter', [
            '--titulo' => 'Feliz Natal',
            '--legenda' => 'Boas festas!',
            '--redes' => 'Instagram,LinkedIn,TikTok',
            '--quando' => '2026-12-24 10:00',
            '--pedido' => $pedido->id,
        ])->assertSuccessful();

        $post = Post::where('title', 'Feliz Natal')->firstOrFail();
        $this->assertSame(Post::PENDING, $post->status);
        $this->assertSame(['Instagram', 'LinkedIn'], $post->networks);
        $this->assertSame('done', $pedido->fresh()->status);

        // Um post aprovado não pode ser substituído pelo comando.
        $post->update(['status' => Post::APPROVED]);
        $this->artisan('post:submeter', ['--post' => $post->id, '--legenda' => 'x'])->assertFailed();
    }

    public function test_submeter_avisa_o_aprovador_por_email(): void
    {
        Notification::fake();
        $aprovador = User::factory()->create();

        $this->artisan('post:submeter', ['--titulo' => 'Dica de redes', '--legenda' => "Linha 1\nLinha 2"])
            ->assertSuccessful();

        Notification::assertSentTo($aprovador, PostParaAprovar::class, function ($n) use ($aprovador) {
            $mail = $n->toMail($aprovador);

            return $mail->subject === 'Post para aprovar: Dica de redes'
                && in_array('Linha 2', $mail->introLines, true)
                && str_ends_with($mail->actionUrl, '#post-'.$n->post->id);
        });
    }
}
