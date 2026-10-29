<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Notifications\PostParaAprovar;
use App\Services\PostRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EditorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        // Nos testes não se abre o browser: o "renderer" só regista o pedido.
        $this->app->instance(PostRenderer::class, new class extends PostRenderer
        {
            public array $pedidos = [];

            public function render(Post $post): array
            {
                $this->pedidos[] = $post->design;

                return ['path' => "imagens-posts/design/{$post->id}/post-teste.png", 'avisos' => ['aviso de teste']];
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('imagens-posts/design'));
        parent::tearDown();
    }

    private function postComModelo(array $attrs = []): Post
    {
        return Post::create(array_merge([
            'title' => 'UPS Riello',
            'design' => PostRenderer::exemplo('produto'),
            'networks' => ['Instagram'],
            'status' => Post::CREATING,
        ], $attrs));
    }

    public function test_cria_post_a_partir_de_um_modelo(): void
    {
        $this->actingAs($this->user)->get('/posts/novo')->assertOk()->assertSee('Tipo de post');

        $r = $this->actingAs($this->user)->post('/posts', ['modelo' => 'aviso', 'title' => 'Fecho de Natal']);

        $post = Post::firstWhere('title', 'Fecho de Natal');
        $r->assertRedirect(route('posts.edit', $post));
        $this->assertSame(Post::CREATING, $post->status);
        $this->assertSame('aviso', $post->design['modelo']);
        $this->assertSame('created', $post->reviews()->first()->action);
        $this->actingAs($this->user)->get(route('posts.edit', $post))->assertOk()->assertSee('Guardar e gerar imagem');
    }

    public function test_guardar_limpa_os_dados_e_gera_a_imagem(): void
    {
        $post = $this->postComModelo();

        $this->actingAs($this->user)->post(route('posts.update', $post), [
            'title' => 'UPS Riello (novo)',
            'caption' => 'Legenda nova #ups',
            'networks' => ['Instagram', 'Facebook'],
            'scheduled_at' => '2026-11-10T10:00',
            'design' => json_encode([
                'modelo' => 'produto', 'formato' => '1:1', 'titulo' => "UPS *Riello*\nsempre ligados",
                'icone' => 'energia', 'pontos' => ['Autonomia', ['destaque' => '24h', 'texto' => 'suporte']],
                'fundo' => '../../.env', 'script' => '<script>alert(1)</script>', 'icone_mau' => 'x',
            ]),
        ])->assertRedirect(route('posts.edit', $post))->assertSessionHas('avisos', ['aviso de teste']);

        $post->refresh();
        $this->assertSame('UPS Riello (novo)', $post->title);
        $this->assertSame(['Instagram', 'Facebook'], $post->networks);
        $this->assertSame('2026-11-10 10:00', $post->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame("imagens-posts/design/{$post->id}/post-teste.png", $post->image_path);
        $this->assertSame('1:1', $post->design['formato']);
        $this->assertArrayNotHasKey('fundo', $post->design);      // ficheiro que não está na pasta do post
        $this->assertArrayNotHasKey('script', $post->design);     // campo que os modelos não conhecem
        $this->assertSame('UPS Riello sempre ligados', $post->image_alt);
    }

    public function test_enviar_imagem_para_a_pasta_do_post(): void
    {
        $post = $this->postComModelo();

        $this->actingAs($this->user)
            ->post(route('posts.upload', $post), ['ficheiro' => UploadedFile::fake()->image('Foto Oficial UPS.JPG', 50, 50)])
            ->assertOk()->assertJson(['nome' => 'foto-oficial-ups.jpg']);
        $this->assertFileExists(PostRenderer::pasta($post).'/foto-oficial-ups.jpg');

        $this->actingAs($this->user)
            ->postJson(route('posts.upload', $post), ['ficheiro' => UploadedFile::fake()->create('mau.php', 1, 'text/plain')])
            ->assertStatus(422);
    }

    public function test_enviar_para_aprovacao(): void
    {
        Notification::fake();
        $outro = User::factory()->create();
        $post = $this->postComModelo(['image_path' => 'imagens-posts/x.png', 'scheduled_at' => now()->addDay()]);

        $this->actingAs($this->user)->post(route('posts.submit', $post))->assertRedirect(route('painel'));

        $this->assertSame(Post::PENDING, $post->fresh()->status);
        $this->assertSame('submitted', $post->reviews()->first()->action);
        Notification::assertSentTo($outro, PostParaAprovar::class);
        Notification::assertNotSentTo($this->user, PostParaAprovar::class);
    }

    public function test_nao_envia_sem_imagem_nem_data(): void
    {
        $post = $this->postComModelo();
        $this->actingAs($this->user)->post(route('posts.submit', $post))->assertSessionHas('erro');
        $this->assertSame(Post::CREATING, $post->fresh()->status);
    }

    public function test_post_aprovado_nao_se_edita(): void
    {
        $post = $this->postComModelo(['status' => Post::APPROVED]);

        $this->actingAs($this->user)->get(route('posts.edit', $post))->assertOk()->assertSee('Desfazer');
        $this->actingAs($this->user)->post(route('posts.update', $post), [
            'title' => 'mudado', 'networks' => ['Instagram'],
        ])->assertStatus(409);
        $this->assertSame('UPS Riello', $post->fresh()->title);
    }

    public function test_post_sem_modelo_edita_so_legenda_e_pode_passar_a_usar_modelo(): void
    {
        $post = $this->postComModelo(['design' => null, 'status' => Post::PENDING, 'image_path' => 'imagens-posts/x.png']);

        $this->actingAs($this->user)->post(route('posts.update', $post), [
            'title' => 'UPS Riello', 'caption' => 'Só a legenda', 'networks' => ['Facebook'],
        ])->assertSessionHas('ok');
        $this->assertSame('Só a legenda', $post->fresh()->caption);
        $this->assertSame('edited', $post->reviews()->first()->action); // post na fila: fica no histórico

        $this->actingAs($this->user)->post(route('posts.template', $post), ['modelo' => 'dica']);
        $this->assertSame('UPS Riello', $post->fresh()->design['titulo']);
    }

    public function test_mudar_a_data_na_lista(): void
    {
        $post = $this->postComModelo(['status' => Post::PENDING, 'scheduled_at' => now()->addDay()]);
        $nova = now()->addDays(10)->setTime(17, 0);

        $this->actingAs($this->user)->get('/')->assertSee('Mudar o dia e a hora de publicação');
        $this->actingAs($this->user)->post(route('posts.date', $post), ['scheduled_at' => $nova->format('Y-m-d\TH:i')])
            ->assertSessionHas('ok');

        $this->assertSame($nova->format('Y-m-d H:i'), $post->fresh()->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('rescheduled', $post->reviews()->first()->action);

        // Passado: recusado. Aprovado: não se mexe (desfazer primeiro).
        $this->actingAs($this->user)->post(route('posts.date', $post), ['scheduled_at' => now()->subDay()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('scheduled_at');
        $post->update(['status' => Post::APPROVED]);
        $this->actingAs($this->user)->post(route('posts.date', $post), ['scheduled_at' => $nova->format('Y-m-d\TH:i')])
            ->assertStatus(409);
    }

    public function test_post_com_foto_da_equipa(): void
    {
        $this->actingAs($this->user)->post('/posts', ['modelo' => 'evento', 'title' => 'A nossa equipa']);
        $post = Post::firstWhere('title', 'A nossa equipa');
        $this->assertSame('evento', $post->design['modelo']);

        $this->actingAs($this->user)
            ->post(route('posts.upload', $post), ['ficheiro' => UploadedFile::fake()->image('Equipa NEXUS.jpg', 80, 100)])
            ->assertJson(['nome' => 'equipa-nexus.jpg']);

        $this->actingAs($this->user)->post(route('posts.update', $post), [
            'title' => 'A nossa equipa', 'networks' => ['Instagram'],
            'design' => json_encode(['modelo' => 'evento', 'titulo' => 'A equipa', 'foto' => 'equipa-nexus.jpg',
                'enquadramento' => 'topo', 'tom' => 'forte']),
        ])->assertSessionHas('ok');

        $d = $post->fresh()->design;
        $this->assertSame(['equipa-nexus.jpg', 'topo', 'forte'], [$d['foto'], $d['enquadramento'], $d['tom']]);
    }

    public function test_layouts_do_feed(): void
    {
        $this->actingAs($this->user)->get('/posts/novo')->assertSee('Número em destaque')->assertSee('Frase')->assertSee('Produto em destaque');

        foreach (['numero', 'frase', 'montra'] as $m) {
            $this->actingAs($this->user)->post('/posts', ['modelo' => $m, 'title' => "Teste $m"]);
            $post = Post::firstWhere('title', "Teste $m");
            $this->assertSame($m, $post->design['modelo']);
            $this->actingAs($this->user)->get(route('posts.edit', $post))->assertOk();
        }
        $this->assertSame('5', Post::firstWhere('title', 'Teste numero')->design['numero']);
    }

    public function test_visitante_nao_edita(): void
    {
        $post = $this->postComModelo();
        $this->get(route('posts.edit', $post))->assertRedirect('/entrar');
        $this->post(route('posts.upload', $post))->assertRedirect('/entrar');
    }
}
