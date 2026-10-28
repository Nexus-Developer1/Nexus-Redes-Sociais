<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class PublicacaoTest extends TestCase
{
    use RefreshDatabase;

    private string $image = 'imagens-posts/teste-publicacao.png';

    protected function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists(public_path('imagens-posts'));
        File::put(public_path($this->image), 'png-falso');
        Sleep::fake();
        config(['services.meta' => [
            'graph_version' => 'v23.0',
            'page_id' => 'PAGE',
            'instagram_user_id' => 'IG',
            'page_token' => 'TOKEN',
        ]]);
    }

    protected function tearDown(): void
    {
        File::delete(public_path($this->image));
        parent::tearDown();
    }

    private function aprovado(array $attrs = []): Post
    {
        return Post::create(array_merge([
            'title' => 'Dica',
            'caption' => 'Legenda',
            'image_path' => $this->image,
            'networks' => ['Instagram', 'Facebook'],
            'scheduled_at' => now()->subMinute(),
            'status' => Post::APPROVED,
        ], $attrs));
    }

    /** Com $igFalha = true, o envio da foto temporária (passo do Instagram) falha. */
    private bool $igFalha = false;

    private function fakeMeta(): void
    {
        Http::fake([
            '*/PAGE/photos' => fn ($req) => str_contains($req->body(), 'name="published"'."\r\n\r\n".'true')
                ? Http::response(['id' => 'FOTO1', 'post_id' => 'PAGE_POST1'])
                : ($this->igFalha
                    ? Http::response(['error' => ['message' => 'Token inválido']], 400)
                    : Http::response(['id' => 'FOTO_TEMP'])),
            '*/FOTO_TEMP?fields=images' => Http::response(['images' => [['source' => 'https://scontent.fbcdn.net/x.jpg']]]),
            '*/FOTO_TEMP' => Http::response(['success' => true]),
            '*/IG/media' => Http::response(['id' => 'CONT1']),
            '*/CONT1*' => Http::response(['status_code' => 'FINISHED']),
            '*/IG/media_publish' => Http::response(['id' => 'IGMEDIA1']),
        ]);
    }

    public function test_publica_so_aprovados_cuja_hora_chegou(): void
    {
        $this->fakeMeta();
        $pronto = $this->aprovado();
        $futuro = $this->aprovado(['scheduled_at' => now()->addDay()]);
        $pendente = $this->aprovado(['status' => Post::PENDING]);

        $this->artisan('post:publicar')->assertSuccessful();

        $pronto->refresh();
        $this->assertSame(Post::PUBLISHED, $pronto->status);
        $this->assertSame('PAGE_POST1', $pronto->facebook_post_id);
        $this->assertSame('IGMEDIA1', $pronto->instagram_media_id);
        $this->assertNotNull($pronto->published_at);
        $this->assertSame('published', $pronto->reviews()->first()->action);

        $this->assertSame(Post::APPROVED, $futuro->fresh()->status);
        $this->assertSame(Post::PENDING, $pendente->fresh()->status);

        // O Instagram recebeu o endereço da foto temporária e a legenda.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/IG/media')
            && $r['image_url'] === 'https://scontent.fbcdn.net/x.jpg' && $r['caption'] === 'Legenda');
        // A foto temporária foi apagada.
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/FOTO_TEMP'));
    }

    public function test_sem_configuracao_nao_publica_nada(): void
    {
        Http::fake();
        config(['services.meta.page_token' => null]);
        $post = $this->aprovado();

        $this->artisan('post:publicar')->assertSuccessful();

        $this->assertSame(Post::APPROVED, $post->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_simular_nao_publica(): void
    {
        Http::fake();
        $post = $this->aprovado();

        $this->artisan('post:publicar', ['--simular' => true])
            ->expectsOutputToContain('Seria publicado: #'.$post->id)
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(Post::APPROVED, $post->fresh()->status);
    }

    public function test_falha_no_instagram_guarda_erro_e_nao_repete_facebook(): void
    {
        $this->igFalha = true;
        $this->fakeMeta();
        $post = $this->aprovado(['networks' => ['Facebook', 'Instagram']]);

        $this->artisan('post:publicar')->assertSuccessful();

        $post->refresh();
        $this->assertSame(Post::APPROVED, $post->status);
        $this->assertSame('PAGE_POST1', $post->facebook_post_id);
        $this->assertSame(1, $post->publish_attempts);
        $this->assertStringContainsString('Token inválido', $post->publish_error);

        // Na tentativa seguinte o Facebook não é publicado outra vez.
        $this->igFalha = false;
        $this->artisan('post:publicar')->assertSuccessful();
        $this->assertSame(Post::PUBLISHED, $post->fresh()->status);
        $publicacoesFacebook = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/PAGE/photos')
            && str_contains($pair[0]->body(), 'name="published"'."\r\n\r\n".'true'));
        $this->assertCount(1, $publicacoesFacebook);
    }

    public function test_para_depois_de_tres_tentativas(): void
    {
        Http::fake();
        $this->aprovado()->forceFill(['publish_attempts' => Post::MAX_PUBLISH_ATTEMPTS])->save();

        $this->artisan('post:publicar')->expectsOutput('Nada para publicar.')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_ideias_criar_trocar_e_descartar(): void
    {
        $user = User::factory()->create();

        $this->artisan('post:ideia', [
            '--titulo' => 'Dica: phishing',
            '--quando' => '2026-11-10 10:00',
            '--tipo' => 'dica',
            '--redes' => 'Instagram,Facebook,LinkedIn',
        ])->assertSuccessful();
        $ideia = Post::where('title', 'Dica: phishing')->firstOrFail();
        $this->assertSame(Post::IDEA, $ideia->status);
        $this->assertSame('Tipo: dica.', $ideia->source);

        $this->actingAs($user)->get('/?ver=ideias')->assertOk()->assertSee('Trocar tema')->assertSee('Dica: phishing');

        $this->actingAs($user)->post("/posts/{$ideia->id}/trocar-ideia", ['comment' => 'Prefiro UPS Riello'])->assertRedirect();
        $ideia->refresh();
        $this->assertSame(Post::IDEA, $ideia->status);
        $this->assertSame('Prefiro UPS Riello', $ideia->change_request);
        $this->artisan('post:pendentes')->expectsOutputToContain('Prefiro UPS Riello')->assertSuccessful();

        // O Claude troca a ideia: o pedido fica resolvido.
        $this->artisan('post:ideia', ['--post' => $ideia->id, '--titulo' => 'UPS Riello', '--tipo' => 'produto'])->assertSuccessful();
        $this->assertNull($ideia->fresh()->change_request);

        $this->actingAs($user)->post("/posts/{$ideia->id}/descartar")->assertRedirect();
        $this->assertSame(Post::REJECTED, $ideia->fresh()->status);

        // Só ideias podem ser trocadas.
        $this->actingAs($user)->post("/posts/{$ideia->id}/trocar-ideia", ['comment' => 'x'])->assertStatus(409);
    }
}
