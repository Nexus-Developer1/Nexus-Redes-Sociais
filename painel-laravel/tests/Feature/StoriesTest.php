<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class StoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('imagens-posts/stories-teste'));
        parent::tearDown();
    }

    public function test_posts_do_instagram_vao_tambem_para_os_stories(): void
    {
        $this->assertSame(['Instagram', 'Stories', 'Facebook'], Post::comStories(['Facebook', 'Instagram']));
        $this->assertSame(['Facebook', 'LinkedIn'], Post::comStories(['LinkedIn', 'Facebook'])); // sem Instagram, sem Stories
        $this->assertSame(['Instagram'], Post::comStories(['Instagram', 'Stories'], false));     // tirados à mão, ficam fora
    }

    public function test_guarda_a_versao_dos_stories_e_o_cartao_mostra_a(): void
    {
        $post = Post::create(['title' => 'Aviso', 'networks' => ['Instagram', 'Stories'], 'status' => Post::PENDING]);
        $post->guardarImagens(['path' => 'imagens-posts/a.png', 'slides' => [], 'story' => 'imagens-posts/a-story.png', 'avisos' => []]);

        $this->assertSame('imagens-posts/a-story.png', $post->fresh()->imagemStory());
        $this->actingAs(User::factory()->create())->get(route('painel'))
            ->assertSee('Stories ↗')->assertSee('imagens-posts/a-story.png');

        // Sem versão 9:16 (ex.: imagem feita à parte), os Stories usam a imagem do post.
        $post->guardarImagens(['path' => 'imagens-posts/b.png', 'avisos' => []]);
        $this->assertSame('imagens-posts/b.png', $post->fresh()->imagemStory());
    }

    public function test_publica_nos_stories_do_instagram(): void
    {
        Sleep::fake();
        config(['services.meta' => ['graph_version' => 'v23.0', 'page_id' => 'PAGE', 'instagram_user_id' => 'IG', 'page_token' => 'TOKEN']]);
        File::ensureDirectoryExists(public_path('imagens-posts/stories-teste'));
        File::put(public_path('imagens-posts/stories-teste/feed.png'), 'feed');
        File::put(public_path('imagens-posts/stories-teste/story.png'), 'story');
        $post = Post::create(['title' => 'Aviso', 'caption' => 'Legenda', 'networks' => ['Stories'],
            'image_path' => 'imagens-posts/stories-teste/feed.png', 'imagem_story' => 'imagens-posts/stories-teste/story.png',
            'scheduled_at' => now()->subMinute(), 'status' => Post::APPROVED]);

        Http::fake(function (Request $r) {
            $url = $r->url();

            return match (true) {
                str_ends_with($url, '/PAGE/photos') => Http::response(['id' => 'FOTO']),
                str_contains($url, '?fields=images') => Http::response(['images' => [['source' => 'https://cdn.meta/story.jpg']]]),
                $r->method() === 'DELETE' => Http::response(['success' => true]),
                str_ends_with($url, '/IG/media') => Http::response(['id' => 'CONT']),
                str_contains($url, 'fields=status_code') => Http::response(['status_code' => 'FINISHED']),
                str_ends_with($url, '/IG/media_publish') => Http::response(['id' => 'STORY1']),
                default => Http::response(['error' => ['message' => 'inesperado '.$url]], 400),
            };
        });

        $this->artisan('post:publicar')->assertSuccessful();

        $post->refresh();
        $this->assertSame(Post::PUBLISHED, $post->status);
        $this->assertSame('STORY1', $post->instagram_story_id);
        // Foi a imagem 9:16 que seguiu, como Story (sem legenda: os Stories não têm).
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/PAGE/photos') && str_contains($r->body(), 'story'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/IG/media') && $r['media_type'] === 'STORIES'
            && $r['image_url'] === 'https://cdn.meta/story.jpg' && ! isset($r['caption']));
    }
}
