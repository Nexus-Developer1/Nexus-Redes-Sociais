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
        $this->assertSame(['Instagram', 'Stories', 'LinkedIn'], $post->networks); // "TikTok" não existe; os do Instagram vão também para os Stories
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
                ->push(['choices' => [['message' => ['content' => '{}']]]]) // revisor PT-PT: nada a mudar
                ->push(['choices' => [['message' => ['content' => '123']]]]),
            'api.pexels.com/v1/search*' => Http::response(['photos' => [
                ['id' => 123, 'alt' => 'Servidores', 'photographer' => 'Ana Silva', 'src' => ['medium' => 'https://images.pexels.com/m.jpg']],
            ]]),
            'api.pexels.com/v1/photos/123' => Http::response(['id' => 123, 'photographer' => 'Ana Silva',
                'src' => ['original' => 'https://images.pexels.com/photos/123/original.jpeg']]),
            'images.pexels.com/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
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

    public function test_nao_repete_a_foto_de_outro_post(): void
    {
        config(['services.pexels.key' => null, 'services.pixabay.key' => 'pixabay-teste']);
        $hit = fn (int $id) => ['id' => $id, 'tags' => 'server', 'user' => 'Rui', 'webformatURL' => "https://pixabay.com/get/w{$id}.jpg",
            'largeImageURL' => "https://pixabay.com/get/large{$id}.jpg", 'imageWidth' => 3000, 'imageHeight' => 4000];
        // Outro post já usa a foto 77 (e a da biblioteca 5); um rejeitado usa a 9000, que fica livre.
        Post::create(['title' => 'Outro', 'networks' => ['Instagram'], 'status' => Post::PENDING, 'design' => ['modelo' => 'dica', 'foto' => 'pixabay-77.jpg']]);
        Post::create(['title' => 'Biblioteca', 'networks' => ['Instagram'], 'status' => Post::APPROVED, 'design' => ['modelo' => 'dica', 'foto' => 'bib-5.jpg']]);
        Post::create(['title' => 'Rejeitado', 'networks' => ['Instagram'], 'status' => Post::REJECTED, 'design' => ['modelo' => 'dica', 'foto' => 'pixabay-9000.jpg']]);
        $this->assertSame(['banco' => ['pixabay-77'], 'biblioteca' => [5]], Post::fotosEmUso());

        Http::fake([
            'ia.teste/*' => Http::sequence()->push($this->respostaIa(['pesquisa_imagem' => 'server room']))
                ->push(['choices' => [['message' => ['content' => '{}']]]])
                ->push(['choices' => [['message' => ['content' => '9000']]]]),
            'pixabay.com/api/*' => Http::response(['hits' => [$hit(77), $hit(9000)]]),
            'pixabay.com/get/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        PostRequest::create(['topic' => 'Servidores']);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $this->assertSame('pixabay-9000.jpg', PostRequest::first()->post->design['foto']);
        // A IA nem chegou a ver a foto 77 entre as candidatas.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'ia.teste') && str_contains($r['messages'][1]['content'] ?? '', '9000')
            && ! str_contains($r['messages'][1]['content'] ?? '', '"id":77') && ! preg_match('/\b77\b/', $r['messages'][1]['content'] ?? ''));
    }

    public function test_sem_pexels_usa_o_pixabay(): void
    {
        config(['services.pexels.key' => null, 'services.pixabay.key' => 'pixabay-teste']);
        $hit = ['id' => 77, 'tags' => 'server, rack', 'user' => 'Rui', 'webformatURL' => 'https://pixabay.com/get/w.jpg',
            'largeImageURL' => 'https://pixabay.com/get/large.jpg', 'imageWidth' => 3000, 'imageHeight' => 4000];
        Http::fake([
            'ia.teste/*' => Http::sequence()->push($this->respostaIa(['pesquisa_imagem' => 'server room']))
                ->push(['choices' => [['message' => ['content' => '{}']]]]) // revisor PT-PT: nada a mudar
                ->push(['choices' => [['message' => ['content' => '77']]]]),
            'pixabay.com/api/*' => Http::response(['hits' => [$hit]]),
            'pixabay.com/get/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        PostRequest::create(['topic' => 'UPS Riello']);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $post = PostRequest::first()->post;
        $this->assertSame('pixabay-77.jpg', $post->design['foto']);
        $this->assertStringContainsString('Foto: Rui (Pixabay)', $post->source);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'pixabay.com/api/') && $r['key'] === 'pixabay-teste');
        // primeiro na categoria de informática do Pixabay
        $this->assertSame('computer', collect(Http::recorded())->map(fn ($p) => $p[0])
            ->first(fn ($r) => str_contains($r->url(), 'pixabay.com/api/'))['category'] ?? null);
    }

    public function test_se_nenhuma_foto_serve_fica_sem_foto(): void
    {
        config(['services.pexels.key' => null, 'services.pixabay.key' => 'pixabay-teste']);
        Http::fake([
            'ia.teste/*' => Http::sequence()->push($this->respostaIa(['pesquisa_imagem' => 'office ups']))
                ->push(['choices' => [['message' => ['content' => '{}']]]])
                ->push(['choices' => [['message' => ['content' => '0']]]]),
            'pixabay.com/api/*' => Http::response(['hits' => [['id' => 5, 'tags' => 'ribs, barbecue', 'user' => 'x',
                'webformatURL' => 'https://pixabay.com/get/w.jpg', 'largeImageURL' => 'https://pixabay.com/get/l.jpg', 'imageWidth' => 3000, 'imageHeight' => 4000]]]),
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
        $hit = fn (int $id, string $tags) => ['id' => $id, 'tags' => $tags, 'user' => 'Ana', 'webformatURL' => "https://pixabay.com/get/w{$id}.jpg",
            'largeImageURL' => "https://pixabay.com/get/l{$id}.jpg", 'imageWidth' => 3000, 'imageHeight' => 4000];
        // 838377 é a atual; 838378 é da mesma série (quase igual); 5000 é outra foto a sério
        $hits = [$hit(838377, 'cable'), $hit(838378, 'cable yellow'), $hit(5000, 'technician server room')];
        Http::fake([
            'ia.teste/*' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => 'it technician server room']]]]) // pesquisa nova
                ->push(['choices' => [['message' => ['content' => '5000']]]]),                     // escolha da foto
            'pixabay.com/api/*' => function ($r) use ($hits) {
                return Http::response(['hits' => isset($r['id']) ? array_values(array_filter($hits, fn ($h) => $h['id'] == $r['id'])) : $hits]);
            },
            'pixabay.com/get/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $user = User::factory()->create();
        $post = Post::create(['title' => 'Backup', 'networks' => ['Instagram'], 'status' => Post::PENDING,
            'design' => ['modelo' => 'dica', 'titulo' => 'Backup', 'pesquisa' => 'network cables', 'fotos_usadas' => [838377], 'foto' => 'pixabay-838377.jpg']]);
        File::ensureDirectoryExists(PostRenderer::pasta($post));
        File::put(PostRenderer::pasta($post).'/pixabay-838377.jpg', 'x');

        $this->actingAs($user)->get('/')->assertSee('Outra foto');
        $this->actingAs($user)->post(route('posts.otherPhoto', $post))->assertSessionHas('ok');

        $post->refresh();
        $this->assertSame('pixabay-5000.jpg', $post->design['foto']);
        $this->assertSame([838377, 5000], $post->design['fotos_usadas']);
        $this->assertSame(['network cables', 'it technician server room'], $post->design['pesquisas']);
        $this->assertSame(Post::PENDING, $post->status);
        $this->assertFileDoesNotExist(PostRenderer::pasta($post).'/pixabay-838377.jpg'); // a antiga sai
        $this->assertSame('photo_changed', $post->reviews()->first()->action);
        // A pesquisa nova sabe o que já se procurou; a IA só vê a foto 5000 (a 838378 é da mesma série).
        Http::assertSent(fn ($r) => str_contains($r->url(), 'ia.teste') && str_contains($r['messages'][1]['content'] ?? '', 'Pesquisas já usadas: network cables'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'ia.teste') && str_contains($r['messages'][1]['content'] ?? '', '5000:')
            && ! str_contains($r['messages'][1]['content'] ?? '', '838378:'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'pixabay.com/api/') && ($r['q'] ?? '') === 'it technician server room');
    }

    public function test_ia_na_legenda_devolve_legenda_nova_sem_guardar(): void
    {
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => '"Legenda **mais** curta.\nContacte‑nos.\n#NEXUS"']]]])
            ->push(['choices' => [['message' => ['content' => '{}']]]]), // revisor PT-PT: nada a mudar
        ]);
        $user = User::factory()->create();
        $post = Post::create(['title' => 'Backup', 'caption' => 'Legenda antiga muito comprida', 'networks' => ['Instagram'],
            'status' => Post::PENDING, 'design' => PostRenderer::exemplo('dica')]);

        $this->actingAs($user)->get(route('posts.edit', $post))->assertSee('Mais profissional');
        $this->actingAs($user)->postJson(route('posts.captionAi', $post), [
            'acao' => 'curta', 'legenda' => 'Legenda antiga muito comprida', 'titulo' => 'O seu *backup*',
        ])->assertOk()->assertJson(['legenda' => "Legenda mais curta.\nContacte-nos.\n#NEXUS"]); // sem aspas nem hífen especial

        $this->assertSame('Legenda antiga muito comprida', $post->fresh()->caption); // só guarda com "Guardar"
        Http::assertSent(fn ($r) => str_contains($r['messages'][1]['content'], 'mais curta')
            && str_contains($r['messages'][1]['content'], 'O seu backup'));

        $this->actingAs($user)->postJson(route('posts.captionAi', $post), ['acao' => 'apagar-tudo'])->assertStatus(422);
    }

    public function test_revisor_pt_corrige_os_textos_do_post(): void
    {
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push($this->respostaIa(['texto' => 'Proteja a sua equipe.', 'legenda' => 'Fica atento!
#NEXUS']))
            ->push(['choices' => [['message' => ['content' => json_encode(['legenda' => "Esteja atento!
#NEXUS", 'design.texto' => 'Proteja a sua equipa.'])]]]]),
        ]);
        PostRequest::create(['topic' => 'tema']);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $post = PostRequest::first()->post;
        $this->assertSame("Esteja atento!
#NEXUS", $post->caption);
        $this->assertSame('Proteja a sua equipa.', $post->design['texto']);
    }

    public function test_sem_revisor_o_texto_nao_se_usa(): void
    {
        // A IA escreve o post mas o revisor esbarra no limite: o pedido volta à fila, sem post.
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push($this->respostaIa())
            ->push(['error' => ['message' => 'rate limit']], 429),
        ]);
        $pedido = PostRequest::create(['topic' => 'tema']);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $this->assertSame('new', $pedido->fresh()->status);
        $this->assertSame(0, Post::count());
    }

    public function test_ia_pode_escolher_numero_em_destaque(): void
    {
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push($this->respostaIa(['modelo' => 'numero', 'numero' => '5', 'titulo' => 'sinais de que
precisa de *renovar*', 'pontos' => []]))
            ->push(['choices' => [['message' => ['content' => '{}']]]]),
        ]);
        PostRequest::create(['topic' => '5 sinais']);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $d = PostRequest::first()->post->design;
        $this->assertSame(['numero', '5'], [$d['modelo'], $d['numero']]);
    }

    public function test_so_fotos_que_ficam_nitidas(): void
    {
        config(['services.pexels.key' => null, 'services.pixabay.key' => 'pix']);
        Http::fake(['pixabay.com/api/*' => Http::response(['hits' => [
            // panorâmica: teria de ser ampliada 3x para encher o post 4:5 → fica de fora
            ['id' => 1, 'tags' => 'server', 'user' => 'a', 'webformatURL' => 'https://pixabay.com/get/1.jpg', 'imageWidth' => 4000, 'imageHeight' => 1400],
            // vertical: quase não é ampliada → serve
            ['id' => 2, 'tags' => 'server', 'user' => 'b', 'webformatURL' => 'https://pixabay.com/get/2.jpg', 'imageWidth' => 3000, 'imageHeight' => 3750],
        ]])]);

        $fotos = app(\App\Services\BancoFotos::class)->procurar('server room', '4:5', 5);

        $this->assertSame([2], array_column($fotos, 'id'));
    }

    public function test_ia_faz_o_post_a_partir_de_uma_ideia(): void
    {
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push($this->respostaIa(['publicar_em' => now()->addDays(30)->format('Y-m-d').' 09:00']))
            ->push(['choices' => [['message' => ['content' => '{}']]]]),
        ]);
        $user = User::factory()->create();
        $quando = now()->addDays(10)->setTime(10, 0);
        $ideia = Post::create(['title' => 'UPS Riello', 'networks' => ['Instagram'], 'status' => Post::IDEA,
            'scheduled_at' => $quando, 'source' => 'Tipo: produto. Inverno = mais cortes.']);

        $this->actingAs($user)->get('/?ver=ideias')->assertSee('A IA faz este post');
        $this->actingAs($user)->post(route('posts.aiMake', $ideia))->assertSessionHas('ok');
        $this->actingAs($user)->get('/?ver=ideias')->assertSee('A IA está a fazer este post');

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $ideia->refresh();
        $this->assertSame(1, Post::count()); // não cria outro post: a ideia passa a ser o post
        $this->assertSame(Post::PENDING, $ideia->status);
        $this->assertNotNull($ideia->image_path);
        $this->assertSame($quando->format('Y-m-d H:i'), $ideia->scheduled_at->format('Y-m-d H:i')); // mantém a data da ideia
        Http::assertSent(fn ($r) => str_contains($r['messages'][1]['content'] ?? '', 'Inverno = mais cortes'));
    }

    public function test_correcoes_fixas_de_brasileirismos(): void
    {
        $this->assertSame(
            'A sua Equipa deve detetar o ficheiro no ecrã do telemóvel. Contacte-nos. Eletrónico, económico, género. Eles têm. Guarde o trabalho. #equipe',
            \App\Services\PostWriter::ptpt('A sua Equipe deve detectar o arquivo na tela do celular. Contate-nos. Eletrônico, econômico, gênero. Eles têm. Salve o trabalho. #equipe')
        );
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
            'formato' => 'imagem',
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
            'topic' => 'tema', 'publish_at' => now()->subDay()->format('Y-m-d\TH:i'), 'formato' => 'imagem',
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
