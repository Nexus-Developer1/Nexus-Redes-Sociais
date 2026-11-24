<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Support\AvisoDatas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** O aviso vem com a data do post: sai na véspera, a imagem diz o dia seguinte e o próximo dia útil. */
class AvisoDatasTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_e_dia_de_volta_saltam_fins_de_semana_e_feriados(): void
    {
        // Publica segunda 30/11 → fecha terça 1/12 (feriado) → volta quarta
        $this->assertSame(['data' => 'Terça-feira, 1 de dezembro', 'texto' => 'Voltamos na quarta-feira. Obrigado pela compreensão!'],
            AvisoDatas::para(Carbon::parse('2026-11-30 10:00')));
        // Publica quinta 24/12 → fecha sexta 25/12 (Natal) → sábado e domingo saltam → volta segunda
        $this->assertSame(['data' => 'Sexta-feira, 25 de dezembro', 'texto' => 'Voltamos na segunda-feira. Obrigado pela compreensão!'],
            AvisoDatas::para(Carbon::parse('2026-12-24 10:00')));
        // Publica 31/12 → fecha 1/1 (Ano Novo, sexta) → volta segunda 4/1
        $this->assertSame('Voltamos na segunda-feira. Obrigado pela compreensão!', AvisoDatas::para(Carbon::parse('2026-12-31 10:00'))['texto']);
    }

    public function test_aviso_criado_com_data_vem_com_a_data_do_post(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('posts.store'), [
            'modelo' => 'aviso', 'title' => 'Feriado de 1 de dezembro', 'data' => '2026-11-30', 'formato' => 'imagem',
        ]);

        $design = Post::latest('id')->first()->design;
        $this->assertSame('Terça-feira, 1 de dezembro', $design['data']);
        $this->assertSame('Voltamos na quarta-feira. Obrigado pela compreensão!', $design['texto']);
    }

    public function test_aviso_sem_data_fica_com_o_exemplo_e_o_editor_recebe_os_feriados(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('posts.store'), ['modelo' => 'aviso', 'title' => 'Aviso', 'formato' => 'imagem']);
        $post = Post::latest('id')->first();

        $this->assertSame('Sexta-feira, 23 de outubro', $post->design['data']); // o editor troca-a quando se escolhe o dia
        $this->actingAs($user)->get(route('posts.edit', $post))
            ->assertOk()
            ->assertSee('feriados:', false)
            ->assertSee(now()->year.'-12-25');
    }

    public function test_acerta_o_dia_da_semana_que_a_ia_errou(): void
    {
        $ref = Carbon::parse('2026-11-26 10:00'); // 30 nov 2026 é segunda; 2 dez é quarta
        $this->assertSame('Segunda-feira, 30 de novembro', AvisoDatas::acertarDiasDaSemana('Sábado, 30 de novembro', $ref));
        $this->assertSame('Segunda-feira, 30 de novembro', AvisoDatas::acertarDiasDaSemana('Sexta-feira, 30 de novembro', $ref));
        $this->assertSame('Voltamos na quarta-feira, 2 de dezembro.', AvisoDatas::acertarDiasDaSemana('Voltamos na quinta-feira, 2 de dezembro.', $ref));
        $this->assertSame('encerra na próxima segunda-feira, 30 de novembro.', AvisoDatas::acertarDiasDaSemana('encerra no próximo sábado, 30 de novembro.', $ref));
        $this->assertSame('Abrimos no domingo, 6 de dezembro', AvisoDatas::acertarDiasDaSemana('Abrimos na sexta-feira, 6 de dezembro', $ref));
        $this->assertSame('SEGUNDA, 30 DE NOVEMBRO', AvisoDatas::acertarDiasDaSemana('SEXTA, 30 DE NOVEMBRO', $ref));
        // já certo, sem dia da semana ou noutro ano (janeiro a seguir): não estraga
        $this->assertSame('Terça-feira, 1 de dezembro', AvisoDatas::acertarDiasDaSemana('Terça-feira, 1 de dezembro', $ref));
        $this->assertSame('Retomamos a 2 de dezembro.', AvisoDatas::acertarDiasDaSemana('Retomamos a 2 de dezembro.', $ref));
        $this->assertSame('sexta-feira, 1 de janeiro', AvisoDatas::acertarDiasDaSemana('domingo, 1 de janeiro', $ref)); // 1 jan 2027
    }

    public function test_calendario_para_a_ia_tem_os_feriados(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 09:00'));
        $cal = AvisoDatas::calendarioParaIa(10);
        $this->assertStringStartsWith('ter 24/11; qua 25/11', $cal);
        $this->assertStringContainsString('seg 30/11; ter 1/12 (feriado: ', $cal);
    }
}

