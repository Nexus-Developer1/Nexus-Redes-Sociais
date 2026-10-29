<?php

namespace Tests\Feature;

use App\Models\ImagemBiblioteca;
use App\Models\Post;
use App\Models\PostRequest;
use App\Models\User;
use App\Services\PostRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BibliotecaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
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
        foreach (ImagemBiblioteca::all() as $i) {
            File::delete($i->caminho());
        }
        parent::tearDown();
    }

    private function carregar(string $tipo, string $descricao): ImagemBiblioteca
    {
        $this->actingAs($this->user)->post('/biblioteca', [
            'tipo' => $tipo, 'descricao' => $descricao,
            'ficheiros' => [UploadedFile::fake()->image('Foto Original.png', 60, 40)],
        ])->assertSessionHas('ok');

        return ImagemBiblioteca::latest('id')->first();
    }

    public function test_carregar_listar_editar_e_remover(): void
    {
        $img = $this->carregar('produto', 'Workstation Pro G3');
        $this->assertFileExists($img->caminho());

        $this->actingAs($this->user)->get('/biblioteca')->assertOk()->assertSee('Workstation Pro G3');
        $this->actingAs($this->user)->getJson('/biblioteca/lista?tipo=produto')->assertJsonPath('imagens.0.descricao', 'Workstation Pro G3');
        $this->actingAs($this->user)->getJson('/biblioteca/lista?tipo=logo')->assertJsonCount(0, 'imagens');

        $this->actingAs($this->user)->post(route('biblioteca.update', $img), ['tipo' => 'produto', 'descricao' => 'Workstation Pro G3, torre preta'])
            ->assertSessionHas('ok');
        $this->assertSame('Workstation Pro G3, torre preta', $img->fresh()->descricao);

        $this->actingAs($this->user)->post(route('biblioteca.destroy', $img))->assertSessionHas('ok');
        $this->assertFileDoesNotExist($img->caminho());
        $this->assertNull($img->fresh());
    }

    public function test_usar_no_post_copia_para_a_pasta_do_post(): void
    {
        $img = $this->carregar('logo', 'NVIDIA');
        $post = Post::create(['title' => 'x', 'networks' => ['Instagram'], 'status' => Post::CREATING, 'design' => PostRenderer::exemplo('montra')]);

        $this->actingAs($this->user)->postJson(route('posts.biblioteca', $post), ['id' => $img->id, 'campo' => 'parceiro'])
            ->assertOk()->assertJson(['nome' => "bib-{$img->id}.png"]);
        $this->assertFileExists(PostRenderer::pasta($post)."/bib-{$img->id}.png");

        // Um logótipo não serve como foto do produto.
        $this->actingAs($this->user)->postJson(route('posts.biblioteca', $post), ['id' => $img->id, 'campo' => 'produto'])->assertNotFound();
    }

    public function test_ia_usa_produto_e_logotipo_da_biblioteca(): void
    {
        Notification::fake();
        config(['ia.chave' => 'x', 'ia.endereco' => 'https://ia.teste/v1', 'services.pixabay.key' => null, 'services.pexels.key' => null]);
        $produto = $this->carregar('produto', 'Workstation NEXUS Pro G3');
        $logo = $this->carregar('logo', 'NVIDIA');
        $post = json_encode([
            'titulo_interno' => 'Pro G3', 'modelo' => 'montra', 'titulo' => 'Workstation *Pro G3*', 'texto' => 'Para CAD e 3D.',
            'legenda' => "Conheça a Pro G3.\n#NEXUS", 'redes' => ['Instagram'], 'publicar_em' => now()->addDays(2)->format('Y-m-d 10:00'),
            'produto_biblioteca' => $produto->id, 'parceiro_biblioteca' => $logo->id, 'pesquisa_imagem' => 'workstation',
        ]);
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => $post]]]])
            ->push(['choices' => [['message' => ['content' => '{}']]]]),
        ]);
        PostRequest::create(['topic' => 'Post sobre a workstation Pro G3']);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $d = PostRequest::first()->post->design;
        $this->assertSame('montra', $d['modelo']);
        $this->assertSame("bib-{$produto->id}.png", $d['produto']);
        $this->assertSame("bib-{$logo->id}.png", $d['parceiro']);
        // a IA recebeu a lista de produtos da biblioteca
        Http::assertSent(fn ($r) => str_contains($r['messages'][1]['content'] ?? '', "{$produto->id}: Workstation NEXUS Pro G3"));
    }

    public function test_ia_prefere_foto_da_biblioteca(): void
    {
        Notification::fake();
        config(['ia.chave' => 'x', 'ia.endereco' => 'https://ia.teste/v1', 'services.pixabay.key' => 'pix']);
        $foto = $this->carregar('foto', 'Equipa técnica a montar um servidor');
        $post = json_encode([
            'titulo_interno' => 'Equipa', 'modelo' => 'dica', 'titulo' => 'A nossa *equipa*', 'texto' => 'x',
            'legenda' => "Texto.\n#NEXUS", 'redes' => ['Instagram'], 'publicar_em' => now()->addDays(2)->format('Y-m-d 10:00'),
            'pesquisa_imagem' => 'it team',
        ]);
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => $post]]]])
            ->push(['choices' => [['message' => ['content' => '{}']]]])
            ->push(['choices' => [['message' => ['content' => (string) $foto->id]]]]),
        ]);
        PostRequest::create(['topic' => 'A nossa equipa técnica']);

        $this->artisan('post:tratar-pedidos')->assertSuccessful();

        $p = PostRequest::first()->post;
        $this->assertSame("bib-{$foto->id}.png", $p->design['foto']);
        $this->assertStringContainsString('Foto da biblioteca NEXUS', $p->source);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'pixabay.com'));
    }
}
