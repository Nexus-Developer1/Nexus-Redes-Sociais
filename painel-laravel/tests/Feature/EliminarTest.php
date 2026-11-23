<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostRequest;
use App\Models\User;
use App\Services\PostRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** "Eliminar post": só posts que nunca saíram, nunca aprovados e sem a IA a trabalhar neles. */
class EliminarTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('imagens-posts/design'));
        parent::tearDown();
    }

    private function novoPost(array $attrs = []): Post
    {
        return Post::create(array_merge([
            'title' => 'Ponte de 1 de dezembro',
            'caption' => 'Estamos fechados.',
            'networks' => ['Instagram'],
            'scheduled_at' => now()->addDay(),
            'status' => Post::CREATING,
            'design' => ['modelo' => 'aviso', 'titulo' => 'Amanhã fechados'],
        ], $attrs));
    }

    public function test_elimina_o_post_as_imagens_e_o_historico(): void
    {
        $user = User::factory()->create();
        $post = $this->novoPost();
        // Número próprio: no Windows, uploads de outros testes podem deixar ficheiros abertos na pasta do post 1.
        $post->forceFill(['id' => 90001])->save();
        $post->reviews()->create(['user_id' => $user->id, 'action' => 'created']);
        $pedido = PostRequest::create(['user_id' => $user->id, 'topic' => 'Ponte', 'status' => 'done', 'post_id' => $post->id]);
        File::ensureDirectoryExists(PostRenderer::pasta($post));
        File::put(PostRenderer::pasta($post).'/post-1.png', 'x');

        $this->actingAs($user)
            ->from(route('posts.edit', $post))
            ->post(route('posts.destroy', $post))
            ->assertRedirect(route('painel'))
            ->assertSessionHas('ok');

        $this->assertNull(Post::find($post->id));
        $this->assertDatabaseMissing('post_reviews', ['post_id' => $post->id]);
        $this->assertDirectoryDoesNotExist(PostRenderer::pasta($post));
        $this->assertNull($pedido->fresh()->post_id); // o pedido fica, sem o post
    }

    public function test_nao_elimina_aprovados_publicados_nem_com_a_ia_a_trabalhar(): void
    {
        $user = User::factory()->create();
        $aprovado = $this->novoPost(['status' => Post::APPROVED]);
        $publicado = $this->novoPost(['status' => Post::PUBLISHED]);
        $jaSaiu = $this->novoPost(['status' => Post::PENDING]);
        $jaSaiu->forceFill(['facebook_post_id' => '123_456'])->save();
        $comIa = $this->novoPost(['status' => Post::IDEA, 'design' => null]);
        PostRequest::create(['user_id' => $user->id, 'topic' => 'Ideia', 'status' => 'in_progress', 'post_id' => $comIa->id]);
        $aRefazer = $this->novoPost(['change_request' => 'Mude a data']);

        foreach ([$aprovado, $publicado, $jaSaiu, $comIa, $aRefazer] as $post) {
            $this->actingAs($user)->post(route('posts.destroy', $post))->assertSessionHas('erro');
            $this->assertNotNull(Post::find($post->id), "o post {$post->status} não devia ter sido eliminado");
        }
    }

    public function test_elimina_rejeitados_ideias_e_posts_a_espera_da_ia(): void
    {
        $user = User::factory()->create();
        $posts = [
            $this->novoPost(['status' => Post::REJECTED]),
            $this->novoPost(['status' => Post::IDEA, 'design' => null]),
            // pedido de alteração ainda por tratar: a IA só pega se o post continuar em REVISING
            $this->novoPost(['status' => Post::REVISING, 'change_request' => 'Outra foto']),
        ];
        foreach ($posts as $post) {
            $this->actingAs($user)->post(route('posts.destroy', $post))->assertSessionHas('ok');
            $this->assertNull(Post::find($post->id));
        }
    }

    public function test_menu_do_editor_e_do_cartao_tem_eliminar(): void
    {
        $user = User::factory()->create();
        $post = $this->novoPost(['status' => Post::PENDING, 'image_path' => 'imagens-posts/teste.png']);

        $this->actingAs($user)->get(route('posts.edit', $post))
            ->assertOk()
            ->assertSee(route('posts.destroy', $post))
            ->assertSee('Texto da imagem');
        $this->actingAs($user)->get(route('painel'))
            ->assertOk()
            ->assertSee(route('posts.destroy', $post));
    }
}
