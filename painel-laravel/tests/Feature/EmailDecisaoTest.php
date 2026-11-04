<?php

namespace Tests\Feature;

use App\Http\Controllers\EmailDecisionController;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostParaAprovar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailDecisaoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['name' => 'Rui']);
        $this->post = Post::create(['title' => 'Dica de backup', 'caption' => 'Legenda', 'networks' => ['Instagram'],
            'status' => Post::PENDING, 'scheduled_at' => now()->addDays(2), 'image_path' => 'imagens-posts/x.png']);
    }

    private function link(string $acao): string
    {
        return EmailDecisionController::link($this->post, $this->user, $acao);
    }

    public function test_abrir_o_link_nao_aprova_so_mostra_a_confirmacao(): void
    {
        $this->get($this->link('aprovar'))->assertOk()
            ->assertSee('Dica de backup')->assertSee('Confirmar aprovação')->assertSee('em nome de');

        $this->assertSame(Post::PENDING, $this->post->fresh()->status);
    }

    public function test_confirmar_aprova_em_nome_de_quem_recebeu_o_email(): void
    {
        $link = $this->link('aprovar');
        $this->get($link);
        $this->post($link)->assertOk()->assertSee('foi aprovado');

        $post = $this->post->fresh();
        $this->assertSame(Post::APPROVED, $post->status);
        $review = $post->reviews()->first();
        $this->assertSame('approved', $review->action);
        $this->assertSame($this->user->id, $review->user_id);
        $this->assertSame('pelo email · IP 127.0.0.1', $review->comment);

        // Outra vez o mesmo link: já não está à espera de aprovação.
        $this->get($link)->assertOk()->assertSee('já não está à espera');
    }

    public function test_pedir_alteracao_pelo_email(): void
    {
        $link = $this->link('alterar');
        $this->get($link)->assertOk()->assertSee('O que quer mudar?');
        $this->post($link, ['comment' => 'Título mais curto'])->assertOk()->assertSee('Pedido de alteração enviado');

        $post = $this->post->fresh();
        $this->assertSame(Post::REVISING, $post->status);
        $this->assertSame('Título mais curto', $post->change_request);
    }

    public function test_link_mexido_ou_caducado_nao_serve(): void
    {
        $link = $this->link('aprovar');
        $this->post(str_replace('/aprovar?', '/alterar?', $link), ['comment' => 'x'])->assertForbidden();
        $this->post(preg_replace('/u=\d+/', 'u=999', $link))->assertForbidden();
        $this->post('/email/posts/'.$this->post->id.'/aprovar')->assertForbidden();

        $this->travel(EmailDecisionController::VALIDADE_DIAS + 1)->days();
        $this->post($link)->assertForbidden();
        $this->assertSame(Post::PENDING, $this->post->fresh()->status);
    }

    public function test_post_alterado_depois_do_email_nao_se_aprova(): void
    {
        $link = $this->link('aprovar');
        $this->travel(1)->minutes();
        $this->post->update(['caption' => 'Legenda nova']);

        $this->post($link)->assertOk()->assertSee('foi alterado depois');
        $this->assertSame(Post::PENDING, $this->post->fresh()->status);
    }

    public function test_emails_de_aprovacao_so_para_os_enderecos_definidos(): void
    {
        Notification::fake();
        config(['painel.emails_aprovacao' => ['rmoreira@nxs.pt']]);
        $suporte = User::factory()->create(['email' => 'suporte@nxs.pt', 'is_admin' => true]);

        PostParaAprovar::enviar($this->post);

        Notification::assertNotSentTo([$suporte, $this->user], PostParaAprovar::class);
        Notification::assertSentOnDemand(PostParaAprovar::class, function ($n, $canais, $destino) {
            return $destino->routes['mail'] === 'rmoreira@nxs.pt' && $n->paraEmail === 'rmoreira@nxs.pt';
        });
    }

    public function test_quem_nao_tem_conta_fica_no_historico_pelo_endereco(): void
    {
        config(['painel.emails_aprovacao' => ['RMoreira@nxs.pt']]);
        $link = EmailDecisionController::link($this->post, $this->user, 'aprovar', 'rmoreira@nxs.pt');
        $this->get($link)->assertOk()->assertSee('rmoreira@nxs.pt');
        $this->post($link)->assertOk();

        $this->assertSame('pelo email (rmoreira@nxs.pt) · IP 127.0.0.1', $this->post->reviews()->first()->comment);
    }

    public function test_endereco_tirado_da_lista_perde_os_links(): void
    {
        config(['painel.emails_aprovacao' => ['rmoreira@nxs.pt']]);
        $link = EmailDecisionController::link($this->post, $this->user, 'aprovar', 'rmoreira@nxs.pt');
        config(['painel.emails_aprovacao' => ['outra@nxs.pt']]);

        $this->post($link)->assertOk()->assertSee('deixou de servir');
        $this->assertSame(Post::PENDING, $this->post->fresh()->status);
    }

    public function test_links_usam_sempre_o_endereco_oficial(): void
    {
        config(['app.url' => 'http://192.168.1.69:8091']);
        // mesmo que o pedido de quem enviou o post venha com outro "Host"
        $this->app['request']->headers->set('HOST', 'maquina-de-outra-pessoa');

        $this->assertStringStartsWith('http://192.168.1.69:8091/email/posts/', $this->link('aprovar'));
    }
}
