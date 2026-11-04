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

    public function test_login_limitado_por_conta(): void
    {
        User::factory()->create(['email' => 'alvo@nxs.pt']);
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/entrar', ['email' => 'alvo@nxs.pt', 'password' => 'errada-'.$i], ['REMOTE_ADDR' => '10.0.0.'.$i])
                ->assertRedirect();
        }
        // 6.ª tentativa à mesma conta, de outro computador: bloqueada
        $this->post('/entrar', ['email' => 'alvo@nxs.pt', 'password' => 'x'], ['REMOTE_ADDR' => '10.0.0.99'])->assertStatus(429);
    }

    public function test_pedidos_a_ia_limitados(): void
    {
        $user = User::factory()->create();
        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($user)->post('/pedidos', ['topic' => 'Tema '.$i, 'formato' => 'imagem'])->assertRedirect();
        }
        $this->actingAs($user)->post('/pedidos', ['topic' => 'Tema 11', 'formato' => 'imagem'])->assertStatus(429);
    }

    public function test_pedir_um_post(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/pedidos', ['topic' => 'Feriado de 1 de dezembro', 'formato' => 'imagem'])->assertRedirect();
        $this->assertDatabaseHas('post_requests', ['topic' => 'Feriado de 1 de dezembro', 'status' => 'new', 'carrossel' => false]);
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

            $html = (string) $mail->render();

            return $mail->subject === 'Post para aprovar: Dica de redes'
                && str_contains($html, 'Linha 1<br />')
                && str_contains($html, '#post-'.$n->post->id)
                && str_contains($html, '/email/posts/'.$n->post->id.'/aprovar?')
                && str_contains($html, '/email/posts/'.$n->post->id.'/alterar?');
        });
    }
    public function test_pagina_expirada_volta_atras_com_o_texto(): void
    {
        // Simula o 419 (formulário de uma página aberta há demasiado tempo).
        \Illuminate\Support\Facades\Route::middleware(['web'])->post('/teste-419', fn () => abort(419));
        $user = User::factory()->create();

        $this->actingAs($user)->from('/')->post('/teste-419', ['topic' => 'Tema escrito'])
            ->assertRedirect('/')
            ->assertSessionHas('erro')
            ->assertSessionHasInput('topic', 'Tema escrito');

        $this->post('/sair');
        $this->post('/teste-419')->assertRedirect(route('login'))->assertSessionHas('erro');
    }
    public function test_aprovar_so_a_versao_que_se_viu(): void
    {
        $user = User::factory()->create();
        $post = $this->novoPost();
        $versaoVista = (string) $post->updated_at->timestamp;
        $this->travel(5)->seconds();
        $post->update(['caption' => 'Legenda mudada por outra pessoa']);

        $this->actingAs($user)->post(route('posts.approve', $post), ['versao' => $versaoVista])->assertSessionHas('erro');
        $this->assertSame(Post::PENDING, $post->fresh()->status);

        $this->actingAs($user)->post(route('posts.approve', $post), ['versao' => (string) $post->fresh()->updated_at->timestamp]);
        $this->assertSame(Post::APPROVED, $post->fresh()->status);
    }

    public function test_mudar_palavra_passe_termina_as_outras_sessoes(): void
    {
        $user = User::factory()->create(['password' => 'antiga-12345']);
        // Outra sessão (outro computador), aberta com a palavra-passe antiga.
        $this->actingAs($user)->get('/')->assertOk();
        $hashAntigo = $user->getAuthPassword();

        $user->update(['password' => 'nova-pass-123']);

        $this->withSession(['password_hash_web' => $hashAntigo])->actingAs($user->fresh())
            ->get('/')->assertRedirect('/entrar');
    }
}
