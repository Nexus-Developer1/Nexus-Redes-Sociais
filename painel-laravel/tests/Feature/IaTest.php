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
            'legenda' => 'Uma falha de energia pode parar a sua empresa.\nContacte‑nos: nexus‑solutions.pt\n#NEXUS #UPS',
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
        // "\n" à letra e hífens especiais da IA ficam normais
        $this->assertSame("Uma falha de energia pode parar a sua empresa.\nContacte-nos: nexus-solutions.pt\n#NEXUS #UPS", $post->caption);
        $this->assertNotNull($post->image_path);
        $this->assertSame('auto_created', $post->reviews()->first()->action);
        Notification::assertSentTo($user, PostParaAprovar::class);

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer chave-de-teste')
            && str_contains($r['messages'][1]['content'], 'post sobre UPS Riello'));
    }

    private function fingirPexels(): void
    {
        config(['services.pexels.key' => 'pexels-teste']);
        Http::fake([
            // 1.º a IA escreve o post; 2.º escolhe a foto entre as candidatas
            'ia.teste/*' => Http::sequence()->push($this->respostaIa(['pesquisa_imagem' => 'server room']))
                ->push(['choices' => [['message' => ['content' => '123']]]]),
            'api.pexels.com/v1/search*' => Http::response(['photos' => [
                ['id' => 123, 'alt' => 'Servidores', 'photographer' => 'Ana Silva', 'src' => ['medium' => 'https://images.pexels.com/m.jpg']],
            ]]),
            'api.pexels.com/v1/photos/123' => Http::response(['id' => 123, 'photographer' => 'Ana Silva',
                'src' => ['original' => 'https://images.pexels.com/photos/123/original.jpeg']]),
            'images.pexels.com/*' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_post_da_ia_leva_foto_do_pexels(): void
    {
        $this->fingirPexels();
        PostRequest::create(['topic' => 'UPS Riello']);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $post = PostRequest::first()->post;
        $this->assertSame('pexels-123.jpg', $post->design['foto']);
        $this->assertArrayNotHasKey('icone', $post->design);
        $this->assertFileExists(PostRenderer::pasta($post).'/pexels-123.jpg');
        $this->assertStringContainsString('Foto: Ana Silva (Pexels)', $post->source);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'images.pexels.com/photos/123/original.jpeg?')
            && str_contains($r->url(), 'w=1080&h=1350'));
    }

    public function test_procurar_e_usar_foto_no_editor(): void
    {
        $this->fingirPexels();
        $user = User::factory()->create();
        $post = Post::create(['title' => 'x', 'networks' => ['Instagram'], 'status' => Post::CREATING,
            'design' => PostRenderer::exemplo('dica')]);

        $this->actingAs($user)->getJson(route('images.search', ['q' => 'server room']))
            ->assertOk()->assertJsonPath('fotos.0.id', 123);
        $this->actingAs($user)->postJson(route('posts.stock', $post), ['id' => 123])
            ->assertOk()->assertJson(['nome' => 'pexels-123.jpg', 'fotografo' => 'Ana Silva']);
        $this->actingAs($user)->get(route('posts.edit', $post))->assertSee('Procurar imagem grátis');
    }

    public function test_sem_pexels_usa_o_pixabay(): void
    {
        config(['services.pexels.key' => null, 'services.pixabay.key' => 'pixabay-teste']);
        $hit = ['id' => 77, 'tags' => 'server, rack', 'user' => 'Rui', 'webformatURL' => 'https://pixabay.com/get/w.jpg',
            'largeImageURL' => 'https://pixabay.com/get/large.jpg'];
        Http::fake([
            'ia.teste/*' => Http::sequence()->push($this->respostaIa(['pesquisa_imagem' => 'server room']))
                ->push(['choices' => [['message' => ['content' => '77']]]]),
            'pixabay.com/api/*' => Http::response(['hits' => [$hit]]),
            'pixabay.com/get/*' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        PostRequest::create(['topic' => 'UPS Riello']);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $post = PostRequest::first()->post;
        $this->assertSame('pixabay-77.jpg', $post->design['foto']);
        $this->assertStringContainsString('Foto: Rui (Pixabay)', $post->source);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'pixabay.com/api/') && $r['key'] === 'pixabay-teste');
    }

    public function test_se_nenhuma_foto_serve_fica_sem_foto(): void
    {
        config(['services.pexels.key' => null, 'services.pixabay.key' => 'pixabay-teste']);
        Http::fake([
            'ia.teste/*' => Http::sequence()->push($this->respostaIa(['pesquisa_imagem' => 'office ups']))
                ->push(['choices' => [['message' => ['content' => '0']]]]),
            'pixabay.com/api/*' => Http::response(['hits' => [['id' => 5, 'tags' => 'ribs, barbecue', 'user' => 'x',
                'webformatURL' => 'https://pixabay.com/get/w.jpg', 'largeImageURL' => 'https://pixabay.com/get/l.jpg']]]),
        ]);
        PostRequest::create(['topic' => 'UPS']);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $post = PostRequest::first()->post;
        $this->assertSame(Post::PENDING, $post->status);
        $this->assertArrayNotHasKey('foto', $post->design);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'pixabay.com/get/'));
    }

    public function test_outra_foto_escolhe_uma_ainda_nao_usada(): void
    {
        config(['services.pexels.key' => null, 'services.pixabay.key' => 'pixabay-teste']);
        $hits = [
            ['id' => 77, 'tags' => 'server', 'user' => 'Rui', 'webformatURL' => 'https://pixabay.com/get/w77.jpg', 'largeImageURL' => 'https://pixabay.com/get/l77.jpg'],
            ['id' => 88, 'tags' => 'data center', 'user' => 'Ana', 'webformatURL' => 'https://pixabay.com/get/w88.jpg', 'largeImageURL' => 'https://pixabay.com/get/l88.jpg'],
        ];
        Http::fake([
            // a IA só vê a 88 (a 77 já foi usada) e escolhe-a
            'ia.teste/*' => Http::response(['choices' => [['message' => ['content' => '88']]]]),
            'pixabay.com/api/*' => function ($r) use ($hits) {
                return Http::response(['hits' => isset($r['id']) ? array_values(array_filter($hits, fn ($h) => $h['id'] == $r['id'])) : $hits]);
            },
            'pixabay.com/get/*' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $user = User::factory()->create();
        $post = Post::create(['title' => 'Backup', 'networks' => ['Instagram'], 'status' => Post::PENDING,
            'design' => ['modelo' => 'dica', 'titulo' => 'Backup', 'pesquisa' => 'server room', 'fotos_usadas' => [77], 'foto' => 'pixabay-77.jpg']]);
        File::ensureDirectoryExists(PostRenderer::pasta($post));
        File::put(PostRenderer::pasta($post).'/pixabay-77.jpg', 'x');

        $this->actingAs($user)->get('/')->assertSee('Outra foto');
        $this->actingAs($user)->post(route('posts.otherPhoto', $post))->assertSessionHas('ok');

        $post->refresh();
        $this->assertSame('pixabay-88.jpg', $post->design['foto']);
        $this->assertSame([77, 88], $post->design['fotos_usadas']);
        $this->assertSame(Post::PENDING, $post->status);
        $this->assertFileDoesNotExist(PostRenderer::pasta($post).'/pixabay-77.jpg'); // a antiga sai
        $this->assertSame('photo_changed', $post->reviews()->first()->action);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'ia.teste') && str_contains($r['messages'][1]['content'] ?? '', '88:')
            && ! str_contains($r['messages'][1]['content'] ?? '', '77:'));
    }

    public function test_data_escolhida_no_pedido_manda_sobre_a_da_ia(): void
    {
        // A IA sugere outra data; a escolhida por quem pediu é a que fica.
        Http::fake(['ia.teste/*' => Http::response($this->respostaIa(['publicar_em' => now()->addDays(9)->format('Y-m-d').' 10:00']))]);
        $user = User::factory()->create();
        $quando = now()->addDays(20)->setTime(17, 30);

        $this->actingAs($user)->post(route('requests.store'), [
            'topic' => 'Aviso: fechados no feriado',
            'publish_at' => $quando->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors();
        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $post = PostRequest::first()->post;
        $this->assertSame($quando->format('Y-m-d H:i'), $post->scheduled_at->format('Y-m-d H:i'));
        Http::assertSent(fn ($r) => str_contains($r['messages'][1]['content'], 'A data de publicação já está decidida'));
    }

    public function test_data_no_passado_e_recusada(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('requests.store'), [
            'topic' => 'tema', 'publish_at' => now()->subDay()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('publish_at');
        $this->assertSame(0, PostRequest::count());
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
