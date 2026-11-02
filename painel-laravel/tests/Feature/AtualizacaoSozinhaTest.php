<?php

namespace Tests\Feature;

use App\Console\Commands\CalendarioIa;
use App\Models\Post;
use App\Models\PostRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** A página atualiza-se sozinha quando a IA acaba (sem F5). */
class AtualizacaoSozinhaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ia.chave' => 'chave-de-teste']);
        $this->user = User::factory()->create();
    }

    public function test_cartao_marca_o_que_esta_a_espera_da_ia(): void
    {
        $ideia = Post::create(['title' => 'Ideia', 'networks' => ['Instagram'], 'status' => Post::IDEA, 'scheduled_at' => now()->addDays(20)]);
        $this->actingAs($this->user)->post(route('posts.aiMake', $ideia));

        $this->actingAs($this->user)->get(route('painel', ['ver' => 'ideias']))
            ->assertSee('data-espera="post"', false)->assertSee('data-id="'.$ideia->id.'"', false)
            ->assertSee('ia\/estado', false); // endereço do estado da IA no script (JSON)
    }

    public function test_pedir_um_post_fica_a_espera_do_pedido(): void
    {
        $this->actingAs($this->user)->post(route('requests.store'), ['topic' => 'Tema', 'formato' => 'imagem']);
        $pedido = PostRequest::sole();

        $this->actingAs($this->user)->get(route('painel'))
            ->assertSee('data-espera="pedido"', false)->assertSee('data-id="'.$pedido->id.'"', false);
    }

    public function test_estado_da_ia(): void
    {
        $post = Post::create(['title' => 'P', 'networks' => ['Instagram'], 'status' => Post::PENDING]);
        $troca = Post::create(['title' => 'T', 'networks' => ['Instagram'], 'status' => Post::IDEA, 'change_request' => 'outro']);
        $pedido = PostRequest::create(['topic' => 'x', 'status' => 'done', 'post_id' => $post->id]);
        Cache::put(CalendarioIa::A_PROPOR, 1);

        $this->actingAs($this->user)->getJson(route('ia.estado', ['posts' => "{$post->id},{$troca->id}", 'pedidos' => $pedido->id]))
            ->assertOk()
            ->assertJsonPath("posts.{$post->id}.estado", 'pending')
            ->assertJsonPath("posts.{$troca->id}.troca", true)
            ->assertJsonPath("pedidos.{$pedido->id}.post", $post->id)
            ->assertJsonPath('propor', true);

        $this->app['auth']->forgetGuards();
        $this->getJson('/ia/estado')->assertUnauthorized(); // só com sessão iniciada
    }

    public function test_post_pronto_aparece_destacado(): void
    {
        $post = Post::create(['title' => 'Backup vs DR', 'networks' => ['Instagram'], 'status' => Post::PENDING]);

        $this->actingAs($this->user)->get(route('painel', ['pronto' => $post->id]))
            ->assertSee('“Backup vs DR” está pronto para aprovar.', false);
    }
}
