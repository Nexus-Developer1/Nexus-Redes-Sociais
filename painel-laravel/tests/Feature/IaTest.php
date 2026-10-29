<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostRequest;
use App\Models\User;
use App\Notifications\PostParaAprovar;
use App\Services\PostRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ia.chave' => 'chave-de-teste', 'ia.endereco' => 'https://ia.teste/v1']);
        Notification::fake();
        // Sem browser nos testes.
        $this->app->instance(PostRenderer::class, new class extends PostRenderer
        {
            public function render(Post $post): array
            {
                return ['path' => "imagens-posts/design/{$post->id}/post-teste.png", 'avisos' => []];
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('imagens-posts/design'));
        parent::tearDown();
    }

    private function respostaIa(array $extra = []): array
    {
        $post = array_merge([
            'titulo_interno' => 'UPS Riello para servidores',
            'modelo' => 'produto',
            'formato' => '4:5',
            'titulo' => 'UPS *Riello*\nsempre ligados',
            'texto' => 'Energia sem falhas para o seu servidor.',
            'etiqueta' => '',
            'rodape' => 'Energia',
            'icone' => 'energia',
            'cta' => 'Peça a sua proposta',
            'pontos' => [['destaque' => '', 'texto' => 'Instalação pela NEXUS']],
            'data' => '', 'hora' => '',
            'evento' => ['dia' => '', 'mes' => '', 'hora' => '', 'local' => ''],
            'legenda' => "Uma falha de energia pode parar a sua empresa.\n#NEXUS #UPS",
            'redes' => ['Instagram', 'LinkedIn', 'TikTok'],
            'publicar_em' => now()->addDays(3)->format('Y-m-d').' 10:00',
        ], $extra);

        return ['choices' => [['message' => ['content' => json_encode($post)]]]];
    }

    public function test_pedido_vira_post_para_aprovar(): void
    {
        Http::fake(['ia.teste/*' => Http::response($this->respostaIa())]);
        $user = User::factory()->create();
        $pedido = PostRequest::create(['topic' => 'post sobre UPS Riello', 'user_id' => $user->id]);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $pedido->refresh();
        $this->assertSame('done', $pedido->status);
        $post = $pedido->post;
        $this->assertSame(Post::PENDING, $post->status);
        $this->assertSame('UPS Riello para servidores', $post->title);
        $this->assertSame("UPS *Riello*\nsempre ligados", $post->design['titulo']);
        $this->assertSame(['Instagram', 'LinkedIn'], $post->networks); // "TikTok" não existe no painel
        $this->assertSame('10:00', $post->scheduled_at->format('H:i'));
        $this->assertNotNull($post->image_path);
        $this->assertSame('auto_created', $post->reviews()->first()->action);
        Notification::assertSentTo($user, PostParaAprovar::class);

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer chave-de-teste')
            && str_contains($r['messages'][1]['content'], 'post sobre UPS Riello'));
    }

    public function test_alteracao_pedida_e_feita_pela_ia(): void
    {
        Http::fake(['ia.teste/*' => Http::response($this->respostaIa(['titulo' => 'UPS *Riello*\npara datacenters']))]);
        $post = Post::create([
            'title' => 'UPS', 'caption' => 'antiga', 'networks' => ['Instagram'],
            'design' => PostRenderer::exemplo('produto'), 'status' => Post::REVISING,
            'change_request' => 'fala de datacenters', 'scheduled_at' => now()->addDay(),
        ]);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $post->refresh();
        $this->assertSame(Post::PENDING, $post->status);
        $this->assertNull($post->change_request);
        $this->assertSame("UPS *Riello*\npara datacenters", $post->design['titulo']);
        Http::assertSent(fn ($r) => str_contains($r['messages'][1]['content'], 'fala de datacenters'));
    }

    public function test_falha_da_ia_fica_registada_e_pode_repetir(): void
    {
        Http::fake(['ia.teste/*' => Http::response(['error' => ['message' => 'modelo indisponível']], 500)]);
        $user = User::factory()->create();
        $pedido = PostRequest::create(['topic' => 'tema', 'user_id' => $user->id]);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $pedido->refresh();
        $this->assertSame('failed', $pedido->status);
        $this->assertStringContainsString('modelo indisponível', $pedido->error);
        $this->assertSame(0, Post::count());

        $this->actingAs($user)->post(route('requests.retry', $pedido))->assertRedirect();
        $this->assertSame('new', $pedido->fresh()->status);
    }

    public function test_limite_do_plano_gratuito_deixa_o_pedido_na_fila(): void
    {
        Http::fake(['ia.teste/*' => Http::response(['error' => ['message' => 'rate limit']], 429)]);
        $pedido = PostRequest::create(['topic' => 'tema']);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $this->assertSame('new', $pedido->fresh()->status);
    }

    public function test_sem_chave_nao_faz_nada(): void
    {
        config(['ia.chave' => null]);
        Http::fake();
        $pedido = PostRequest::create(['topic' => 'tema']);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $this->assertSame('new', $pedido->fresh()->status);
        Http::assertNothingSent();
    }
}
