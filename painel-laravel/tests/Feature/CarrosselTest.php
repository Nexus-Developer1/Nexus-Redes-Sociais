<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostRequest;
use App\Models\User;
use App\Services\PostRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CarrosselTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // Sem browser nos testes: devolve uma imagem por slide, como o PostRenderer a sério.
        $this->app->instance(PostRenderer::class, new class extends PostRenderer
        {
            public function render(Post $post): array
            {
                $total = PostRenderer::totalImagens($post->design ?? []);
                $slides = array_map(fn ($i) => "imagens-posts/design/{$post->id}/post-1".($i ? '-'.($i + 1) : '').'.png', range(0, $total - 1));

                return ['path' => $slides[0], 'slides' => $total > 1 ? $slides : [], 'avisos' => []];
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('imagens-posts/design'));
        File::deleteDirectory(public_path('imagens-posts/carrossel-teste'));
        parent::tearDown();
    }

    private function slides(int $n): array
    {
        return array_map(fn ($i) => ['destaque' => (string) $i, 'titulo' => "Ponto $i", 'texto' => "Explicação $i"], range(1, $n));
    }

    public function test_dados_do_carrossel_sao_limpos(): void
    {
        $post = Post::create(['title' => 'x', 'networks' => ['Instagram'], 'status' => Post::CREATING]);

        $d = PostRenderer::limparDesign(['modelo' => 'numero', 'carrossel' => true, 'fecho' => 'Fale *connosco*',
            'slides' => array_merge($this->slides(9), [['titulo' => ''], 'texto solto'])], $post);
        $this->assertTrue($d['carrossel']);
        $this->assertCount(PostRenderer::MAX_SLIDES, $d['slides']);
        $this->assertSame(['destaque' => '1', 'titulo' => 'Ponto 1', 'texto' => 'Explicação 1'], $d['slides'][0]);
        $this->assertSame('Fale *connosco*', $d['fecho']);
        $this->assertSame(10, PostRenderer::totalImagens($d));

        // Stories (9:16) não têm carrossel; sem slides também não.
        $stories = PostRenderer::limparDesign(['formato' => '9:16', 'carrossel' => true, 'fecho' => 'x', 'slides' => $this->slides(3)], $post);
        $this->assertArrayNotHasKey('carrossel', $stories);
        $this->assertArrayNotHasKey('slides', $stories);
        $this->assertArrayNotHasKey('fecho', $stories);
        $this->assertArrayNotHasKey('carrossel', PostRenderer::limparDesign(['carrossel' => true, 'slides' => []], $post));
        $this->assertSame(1, PostRenderer::totalImagens(['modelo' => 'dica']));
    }

    public function test_editor_guarda_o_carrossel_e_o_painel_mostra_as_imagens(): void
    {
        $user = User::factory()->create();
        $post = Post::create(['title' => 'Phishing', 'networks' => ['Instagram'], 'status' => Post::PENDING,
            'design' => PostRenderer::exemplo('numero'), 'scheduled_at' => now()->addDays(2)]);
        $dados = fn (array $design) => ['title' => 'Phishing', 'caption' => 'Legenda', 'networks' => ['Instagram'],
            'scheduled_at' => now()->addDays(2)->format('Y-m-d\TH:i'), 'design' => json_encode($design)];

        $this->actingAs($user)->post(route('posts.update', $post),
            $dados(['modelo' => 'numero', 'numero' => '2', 'titulo' => 'sinais', 'carrossel' => true, 'slides' => $this->slides(2)]))
            ->assertRedirect();

        $post->refresh();
        $this->assertCount(4, $post->imagens_carrossel);
        $this->assertSame($post->imagens_carrossel[0], $post->image_path);
        $this->assertSame($post->imagens_carrossel, $post->imagens());
        $this->actingAs($user)->get(route('painel'))->assertSee('Carrossel · 1 / 4')->assertSee('Imagem 4 de 4');

        // Volta a ser uma imagem só.
        $this->actingAs($user)->post(route('posts.update', $post), $dados(['modelo' => 'numero', 'numero' => '2', 'titulo' => 'sinais']));
        $post->refresh();
        $this->assertNull($post->imagens_carrossel);
        $this->assertSame([$post->image_path], $post->imagens());
    }

    public function test_ia_faz_um_carrossel(): void
    {
        config(['ia.chave' => 'chave-de-teste', 'ia.endereco' => 'https://ia.teste/v1']);
        $resposta = [
            'titulo_interno' => 'Sinais de phishing', 'modelo' => 'dica', 'formato' => '9:16', 'titulo' => 'Sinais de\n*phishing*',
            'texto' => 'Saiba reconhecê-los.', 'pontos' => [['destaque' => '', 'texto' => 'não devia ficar']],
            'carrossel' => true, 'fecho' => 'Fale com a\n*NEXUS*',
            'slides' => [
                ['destaque' => '1', 'titulo' => 'Remetente\n*estranho*', 'texto' => 'Saiba detectar o domínio falso.'],
                ['destaque' => '2', 'titulo' => '*Urgência*', 'texto' => 'Não clique com pressa.'],
            ],
            'legenda' => 'Legenda #NEXUS', 'redes' => ['Instagram'], 'publicar_em' => now()->addDays(3)->format('Y-m-d').' 10:00',
        ];
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => json_encode($resposta)]]]])
            ->push(['choices' => [['message' => ['content' => '{}']]]])]); // revisor: nada a corrigir
        PostRequest::create(['topic' => 'sinais de phishing', 'carrossel' => true]);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $post = Post::sole();
        $this->assertSame(Post::PENDING, $post->status);
        $this->assertTrue($post->design['carrossel']);
        $this->assertSame('4:5', $post->design['formato']);            // carrossel: só feed
        $this->assertArrayNotHasKey('pontos', $post->design);         // os pontos estão nos slides
        $this->assertSame("Remetente\n*estranho*", $post->design['slides'][0]['titulo']);
        $this->assertSame('Saiba detetar o domínio falso.', $post->design['slides'][0]['texto']); // PT-PT
        $this->assertCount(4, $post->imagens_carrossel);

        // O revisor de português recebeu os textos dos slides; a IA soube que tinha de ser carrossel.
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][1]['content'] ?? '', 'slide.1.titulo'));
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][1]['content'] ?? '', 'FORMATO: carrossel'));
    }

    /** Resposta da IA (escrever o post) + revisor sem correções. */
    private function fingirIa(array $post): void
    {
        config(['ia.chave' => 'chave-de-teste', 'ia.endereco' => 'https://ia.teste/v1']);
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => json_encode($post + [
                'titulo_interno' => 'Phishing', 'titulo' => 'Sinais de
*phishing*', 'legenda' => 'Legenda #NEXUS',
                'redes' => ['Instagram'], 'publicar_em' => now()->addDays(3)->format('Y-m-d').' 10:00',
            ])]]]])
            ->push(['choices' => [['message' => ['content' => '{}']]]])]);
    }

    public function test_a_ia_nao_faz_carrossel_se_nao_foi_pedido(): void
    {
        $this->fingirIa(['modelo' => 'dica', 'carrossel' => true, 'slides' => $this->slides(3), 'pontos' => ['a', 'b']]);
        PostRequest::create(['topic' => 'sinais de phishing']); // imagem única (o normal)

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $post = Post::sole();
        $this->assertArrayNotHasKey('carrossel', $post->design);
        $this->assertArrayNotHasKey('slides', $post->design);
        $this->assertSame(['a', 'b'], $post->design['pontos']);
        $this->assertNull($post->imagens_carrossel);
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][1]['content'] ?? '', 'FORMATO: imagem única'));
    }

    public function test_carrossel_pedido_sem_slides_usa_os_pontos(): void
    {
        $this->fingirIa(['modelo' => 'aviso', 'carrossel' => false, 'slides' => [],
            'pontos' => [['destaque' => '', 'texto' => 'Remetente estranho'], ['destaque' => '24h', 'texto' => 'Urgência']]]);
        PostRequest::create(['topic' => 'sinais de phishing', 'carrossel' => true]);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $d = Post::sole()->design;
        $this->assertTrue($d['carrossel']);
        $this->assertSame('dica', $d['modelo']); // aviso não tem carrossel
        $this->assertSame([['destaque' => '1', 'titulo' => 'Remetente estranho'], ['destaque' => '24h', 'titulo' => 'Urgência']], $d['slides']);
    }

    public function test_pedir_um_post_escolhe_o_formato(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('requests.store'), ['topic' => 'x'])->assertSessionHasErrors('formato');
        $this->actingAs($user)->post(route('requests.store'), ['topic' => 'Guia de backup', 'formato' => 'carrossel'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(PostRequest::sole()->carrossel);
        $this->actingAs($user)->get(route('painel'))->assertSee('Imagem única')->assertSee('Carrossel');
    }

    public function test_criar_post_a_mao_em_carrossel(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('posts.store'), ['modelo' => 'aviso', 'title' => 'x', 'formato' => 'carrossel'])
            ->assertSessionHasErrors('formato');

        $this->actingAs($user)->post(route('posts.store'), ['modelo' => 'numero', 'title' => 'Guia', 'formato' => 'carrossel'])
            ->assertRedirect();
        $d = Post::sole()->design;
        $this->assertTrue($d['carrossel']);
        $this->assertCount(3, $d['slides']);
        $this->assertArrayNotHasKey('pontos', $d);
    }

    public function test_publica_carrossel_no_instagram_e_no_facebook(): void
    {
        Sleep::fake();
        config(['services.meta' => ['graph_version' => 'v23.0', 'page_id' => 'PAGE', 'instagram_user_id' => 'IG', 'page_token' => 'TOKEN']]);
        File::ensureDirectoryExists(public_path('imagens-posts/carrossel-teste'));
        $imagens = [];
        foreach ([1, 2, 3] as $i) {
            File::put(public_path($imagens[] = "imagens-posts/carrossel-teste/$i.png"), "png-$i");
        }
        $post = Post::create(['title' => 'Carrossel', 'caption' => 'Legenda', 'networks' => ['Instagram', 'Facebook'],
            'image_path' => $imagens[0], 'imagens_carrossel' => $imagens, 'scheduled_at' => now()->subMinute(), 'status' => Post::APPROVED]);

        $fotos = 0;
        $filhos = 0;
        Http::fake(function (Request $r) use (&$fotos, &$filhos) {
            $url = $r->url();

            return match (true) {
                str_ends_with($url, '/PAGE/photos') => Http::response(['id' => 'FOTO'.(++$fotos)]),
                str_contains($url, '?fields=images') => Http::response(['images' => [['source' => 'https://cdn.meta/'.$fotos.'.jpg']]]),
                $r->method() === 'DELETE' => Http::response(['success' => true]),
                str_ends_with($url, '/IG/media') => Http::response(['id' => isset($r['is_carousel_item']) ? 'FILHO'.(++$filhos) : 'CARROSSEL']),
                str_contains($url, 'fields=status_code') => Http::response(['status_code' => 'FINISHED']),
                str_ends_with($url, '/IG/media_publish') => Http::response(['id' => 'IGMEDIA']),
                str_ends_with($url, '/PAGE/feed') => Http::response(['id' => 'PAGE_FEED']),
                default => Http::response(['error' => ['message' => 'inesperado '.$url]], 400),
            };
        });

        $this->artisan('post:publicar')->assertSuccessful();

        $post->refresh();
        $this->assertSame(Post::PUBLISHED, $post->status);
        $this->assertSame('IGMEDIA', $post->instagram_media_id);
        $this->assertSame('PAGE_FEED', $post->facebook_post_id);
        // Instagram: 3 imagens dentro de um carrossel, com a legenda.
        $this->assertSame(3, $filhos);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/IG/media') && ($r['media_type'] ?? '') === 'CAROUSEL'
            && $r['children'] === 'FILHO1,FILHO2,FILHO3' && $r['caption'] === 'Legenda');
        // Facebook: um só post com as 3 fotos, pela ordem.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/PAGE/feed') && $r['message'] === 'Legenda'
            && count($r['attached_media']) === 3);
    }
}
