<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Notifications\PostParaAprovar;
use App\Support\Miniatura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OtimizacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_lembra_uma_vez_os_posts_por_aprovar_perto_da_data(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $perto = Post::create(['title' => 'Perto', 'networks' => ['Instagram'], 'status' => Post::PENDING,
            'image_path' => 'x.png', 'scheduled_at' => now()->addDay()]);
        $longe = Post::create(['title' => 'Longe', 'networks' => ['Instagram'], 'status' => Post::PENDING,
            'image_path' => 'x.png', 'scheduled_at' => now()->addDays(5)]);
        Post::query()->toBase()->update(['updated_at' => now()->subDay()]); // o primeiro email foi ontem
        $versao = $perto->fresh()->updated_at;

        $this->artisan('post:lembrar')->assertSuccessful();
        $this->artisan('post:lembrar')->assertSuccessful(); // não repete

        Notification::assertSentToTimes($user, PostParaAprovar::class, 1);
        Notification::assertSentTo($user, PostParaAprovar::class, fn ($n) => $n->lembrete && $n->post->is($perto));
        // o lembrete não muda a "versão" do post (os botões do email continuam a funcionar)
        $this->assertTrue($perto->fresh()->updated_at->equalTo($versao));
        $this->assertNotNull($perto->fresh()->lembrete_em);
        $this->assertNull($longe->fresh()->lembrete_em);
    }

    public function test_miniatura_e_pequena_e_o_original_fica(): void
    {
        $caminho = 'imagens-posts/teste-miniatura.png';
        File::ensureDirectoryExists(public_path('imagens-posts'));
        $img = imagecreatetruecolor(1080, 1350);
        imagepng($img, public_path($caminho));

        $url = Miniatura::url($caminho);

        $this->assertStringContainsString(Miniatura::PASTA, $url);
        $ficheiro = public_path(Miniatura::PASTA.'/'.basename($url));
        $this->assertFileExists($ficheiro);
        $this->assertSame(480, getimagesize($ficheiro)[0]);
        $this->assertSame($url, Miniatura::url($caminho)); // a segunda vez usa a que já existe

        File::delete([public_path($caminho), $ficheiro]);
    }

    public function test_miniatura_de_imagem_que_nao_existe_devolve_o_original(): void
    {
        $this->assertSame(asset('imagens-posts/nao-existe.png'), Miniatura::url('imagens-posts/nao-existe.png'));
    }

    public function test_fila_mostra_resumo_e_aviso_da_meta(): void
    {
        config(['services.meta.page_token' => null, 'services.meta.page_id' => null]);
        $user = User::factory()->create();
        Post::create(['title' => 'Atrasado', 'networks' => ['Instagram'], 'status' => Post::PENDING,
            'image_path' => 'x.png', 'scheduled_at' => now()->subHour()]);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('para aprovar')
            ->assertSee('1 atrasado')
            ->assertSee('Publicação automática por ligar')
            ->assertSee('Atrasado');
    }

    public function test_previsualizacao_do_instagram_no_cartao_e_no_editor(): void
    {
        $user = User::factory()->create();
        $post = Post::create(['title' => 'UPS', 'caption' => "Linha forte\n\n<b>não é HTML</b> #NEXUS", 'networks' => ['Instagram'],
            'status' => Post::PENDING, 'image_path' => 'x.png', 'scheduled_at' => now()->addDay()]);
        $soLinkedin = Post::create(['title' => 'Só LinkedIn', 'networks' => ['LinkedIn'], 'status' => Post::PENDING,
            'image_path' => 'y.png', 'scheduled_at' => now()->addDay()]);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('data-ig-abrir="'.$post->id.'"', false)
            ->assertSee('id="ig-'.$post->id.'"', false)
            ->assertDontSee('id="ig-'.$soLinkedin->id.'"', false)
            // a legenda vai como JSON (texto), nunca como HTML
            ->assertDontSee('<b>não é HTML</b>', false);

        $this->actingAs($user)->get(route('posts.edit', $post))
            ->assertOk()
            ->assertSee('data-fonte="#legenda"', false);
    }

    public function test_publicados_vao_por_paginas(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 25) as $i) {
            Post::create(['title' => "Antigo $i", 'networks' => ['Instagram'], 'status' => Post::PUBLISHED,
                'scheduled_at' => now()->subDays($i)]);
        }

        $this->actingAs($user)->get('/?ver=publicados')->assertOk()->assertSee('Mais antigos')->assertSee('Antigo 1')->assertDontSee('Antigo 25');
        $this->actingAs($user)->get('/?ver=publicados&page=2')->assertOk()->assertSee('Antigo 25');
    }
}
