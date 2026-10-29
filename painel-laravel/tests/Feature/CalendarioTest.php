<?php

namespace Tests\Feature;

use App\Http\Controllers\CalendarioController;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CalendarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_mostra_os_posts_do_mes_e_os_feriados(): void
    {
        $user = User::factory()->create();
        Post::create(['title' => 'Aviso de Natal', 'networks' => ['Instagram'], 'status' => Post::PENDING,
            'scheduled_at' => Carbon::parse('2026-12-22 10:00')]);
        Post::create(['title' => 'Post de janeiro', 'networks' => ['Instagram'], 'status' => Post::APPROVED,
            'scheduled_at' => Carbon::parse('2027-01-20 10:00')]);

        $this->actingAs($user)->get('/calendario?mes=2026-12')
            ->assertOk()
            ->assertSee('Dezembro 2026')
            ->assertSee('Aviso de Natal')
            ->assertSee('Restauração da Independência')
            ->assertDontSee('Post de janeiro');
    }

    public function test_feriados_moveis(): void
    {
        $f = CalendarioController::feriados(2027);
        $this->assertSame('Páscoa', $f['2027-03-28']);
        $this->assertSame('Sexta-feira Santa', $f['2027-03-26']);
        $this->assertSame('Corpo de Deus', $f['2027-05-27']);
    }

    public function test_criar_post_a_partir_de_um_dia(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/posts/novo?data=2026-12-22')->assertOk()->assertSee('22 de dezembro', false);

        $this->actingAs($user)->post('/posts', ['modelo' => 'aviso', 'title' => 'Fecho de Natal', 'data' => '2026-12-22']);

        $this->assertSame('2026-12-22 10:00', Post::firstWhere('title', 'Fecho de Natal')->scheduled_at->format('Y-m-d H:i'));
    }

    public function test_visitante_nao_ve_o_calendario(): void
    {
        $this->get('/calendario')->assertRedirect('/entrar');
    }
}
