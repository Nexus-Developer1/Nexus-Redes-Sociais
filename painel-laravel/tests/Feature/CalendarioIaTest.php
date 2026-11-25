<?php

namespace Tests\Feature;

use App\Console\Commands\CalendarioIa;
use App\Models\Post;
use App\Models\PostRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalendarioIaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ia.chave' => 'chave-de-teste', 'ia.endereco' => 'https://ia.teste/v1', 'ia.ideias_por_semana' => 3, 'ia.ideias_dias_antes' => 7]);
        $this->travelTo(Carbon::parse('2026-11-02 09:00')); // segunda-feira
    }

    private function resposta(array $conteudo): array
    {
        return ['choices' => [['message' => ['content' => json_encode($conteudo)]]]];
    }

    private function ideia(string $quando, array $extra = []): Post
    {
        return Post::create(array_merge([
            'title' => 'Dica: backup 3-2-1', 'networks' => ['Instagram'], 'status' => Post::IDEA,
            'scheduled_at' => Carbon::parse($quando), 'source' => 'Tipo: dica.',
        ], $extra));
    }

    public function test_ideia_perto_da_data_e_feita_sozinha(): void
    {
        config(['ia.ideias_por_semana' => 0]); // aqui só interessa a parte de fazer as ideias
        Http::fake();
        $perto = $this->ideia('2026-11-05 10:00');
        $longe = $this->ideia('2026-11-20 10:00');
        $falhou = $this->ideia('2026-11-06 10:00');
        PostRequest::create(['topic' => 'x', 'post_id' => $falhou->id, 'status' => 'failed']);

        $this->artisan('post:calendario-ia')->assertSuccessful();
        $this->artisan('post:calendario-ia')->assertSuccessful(); // não repete

        $this->assertSame(Post::CREATING, $perto->fresh()->status);
        $pedido = PostRequest::where('post_id', $perto->id)->sole();
        $this->assertNull($pedido->user_id);
        $this->assertTrue($pedido->publish_at->equalTo($perto->scheduled_at));
        $this->assertSame('auto_requested', $perto->reviews()->first()->action);
        $this->assertSame(Post::IDEA, $longe->fresh()->status);
        $this->assertSame(Post::IDEA, $falhou->fresh()->status, 'as que falharam ficam para o botão');
        Http::assertNothingSent();
    }

    public function test_dias_antes_a_zero_desliga(): void
    {
        config(['ia.ideias_por_semana' => 0, 'ia.ideias_dias_antes' => 0]);
        $ideia = $this->ideia('2026-11-04 10:00');

        $this->artisan('post:calendario-ia')->assertSuccessful();

        $this->assertSame(Post::IDEA, $ideia->fresh()->status);
    }

    public function test_recomeco_propoe_o_resto_desta_semana_e_as_seguintes(): void
    {
        config(['ia.ideias_por_semana' => 1]);
        $this->travelTo(Carbon::parse('2026-11-24 09:00')); // terça-feira, painel vazio
        $ideias = fn (array $l) => $this->resposta(['ideias' => $l]);
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push($ideias([['titulo' => 'Rede pronta para a Black Friday', 'tipo' => 'dica', 'notas' => '', 'quando' => '2026-11-26 10:00']]))
            ->push($this->resposta([]))
            ->push($ideias([['titulo' => 'Palavras-passe fortes', 'tipo' => 'dica', 'notas' => '', 'quando' => '2026-12-03 10:00'],
                ['titulo' => 'Aviso: feriado de 1 de dezembro', 'tipo' => 'aviso', 'notas' => '', 'quando' => '2026-11-30 17:00']]))
            ->push($this->resposta([]))
            ->push($ideias([['titulo' => 'Workstations para CAD', 'tipo' => 'produto', 'notas' => '', 'quando' => '2026-12-10 10:00'],
                ['titulo' => 'Aviso: feriado de 8 de dezembro', 'tipo' => 'aviso', 'notas' => '', 'quando' => '2026-12-07 17:00']]))
            ->push($this->resposta([]))]);

        $this->artisan('post:calendario-ia', ['--semanas' => 2])->assertSuccessful();

        $datas = Post::orderBy('scheduled_at')->get()->map(fn ($p) => $p->scheduled_at->format('Y-m-d H:i').' '.$p->title)->all();
        $this->assertSame([
            '2026-11-26 10:00 Rede pronta para a Black Friday',   // o resto desta semana
            '2026-11-30 17:00 Aviso: feriado de 1 de dezembro',   // avisos dos feriados: extra
            '2026-12-03 10:00 Palavras-passe fortes',
            '2026-12-07 17:00 Aviso: feriado de 8 de dezembro',
            '2026-12-10 10:00 Workstations para CAD',
        ], $datas);
        $this->assertTrue(Post::all()->every(fn ($p) => $p->status === Post::IDEA && $p->enviar_parceiros
            && in_array('LinkedIn', $p->networks, true)));
    }

    public function test_propoe_as_ideias_da_semana_seguinte(): void
    {
        $this->ideia('2026-11-11 10:00', ['title' => 'Já marcado']); // 1 de 3: faltam 2
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push($this->resposta(['ideias' => [
                ['titulo' => 'Dica: detectar phishing', 'tipo' => 'dica', 'notas' => 'Sinais de alerta.', 'quando' => '2026-11-10 10:00'],
                ['titulo' => 'UPS Riello', 'tipo' => 'produto', 'notas' => 'Proteção contra falhas.', 'quando' => '2026-11-11 18:00'], // dia ocupado
                ['titulo' => 'A mais', 'tipo' => 'dica', 'notas' => '', 'quando' => '2026-11-12 10:00'],
            ]]))
            ->push($this->resposta([]))]); // revisor: nada a corrigir

        $this->artisan('post:calendario-ia')->assertSuccessful();

        $novas = Post::where('title', '!=', 'Já marcado')->orderBy('id')->get();
        $this->assertCount(2, $novas);
        $this->assertSame('Dica: detetar phishing', $novas[0]->title); // correções fixas de PT-PT
        $this->assertSame('2026-11-10 10:00', $novas[0]->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame(Post::IDEA, $novas[0]->status);
        $this->assertSame('Tipo: dica. Sinais de alerta.', $novas[0]->source);
        $this->assertSame('auto_idea', $novas[0]->reviews()->first()->action);
        // o dia 11 já tinha post: vai para o primeiro dia livre da semana, à hora pedida
        $this->assertSame('2026-11-09 18:00', $novas[1]->scheduled_at->format('Y-m-d H:i'));

        Http::assertSent(fn (Request $r) => str_contains($r['messages'][1]['content'] ?? '', 'Propõe 2 temas') && str_contains($r['messages'][1]['content'] ?? '', 'Já marcado'));

        // Só uma vez por semana.
        $this->artisan('post:calendario-ia')->assertSuccessful();
        $this->assertSame(3, Post::count());
        Http::assertSentCount(2);
    }

    public function test_nao_marca_posts_em_feriados(): void
    {
        $this->travelTo(Carbon::parse('2026-11-30 09:00')); // semana seguinte: 7 a 13 de dezembro (8 = feriado)
        config(['ia.ideias_por_semana' => 1]);
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push($this->resposta(['ideias' => [['titulo' => 'Dica de rede', 'tipo' => 'dica', 'notas' => '', 'quando' => '2026-12-08 10:00']]]))
            ->push($this->resposta([]))]);

        $this->artisan('post:calendario-ia')->assertSuccessful();

        $this->assertSame('2026-12-07', Post::sole()->scheduled_at->format('Y-m-d'));
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][1]['content'] ?? '', 'Feriado: Imaculada Concei'));
    }

    public function test_avisos_nao_contam_para_os_posts_da_semana(): void
    {
        config(['ia.ideias_por_semana' => 1]);
        $this->ideia('2026-11-10 17:00', ['title' => 'Aviso: horário especial', 'source' => 'Tipo: aviso.']);
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push($this->resposta(['ideias' => [['titulo' => 'Dica de rede', 'tipo' => 'dica', 'notas' => '', 'quando' => '2026-11-12 10:00']]]))
            ->push($this->resposta([]))]);

        $this->artisan('post:calendario-ia')->assertSuccessful();

        $this->assertSame(2, Post::count());
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][1]['content'] ?? '', 'Propõe 1 temas'));
    }

    public function test_feriado_pede_o_aviso_a_mais_e_fica_so_1_post_normal(): void
    {
        $this->travelTo(Carbon::parse('2026-11-30 09:00')); // semana seguinte: 7 a 13 de dezembro (8 = feriado)
        config(['ia.ideias_por_semana' => 1]);
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push($this->resposta(['ideias' => [
                ['titulo' => 'Dica de rede', 'tipo' => 'dica', 'notas' => '', 'quando' => '2026-12-09 10:00'],
                ['titulo' => 'Dica de backup', 'tipo' => 'dica', 'notas' => '', 'quando' => '2026-12-10 10:00'],
            ]]))
            ->push($this->resposta([]))]);

        $this->artisan('post:calendario-ia')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r['messages'][1]['content'] ?? '', 'Propõe 2 temas'));
        $this->assertSame(['Dica de rede'], Post::pluck('title')->all()); // a 2.ª dica não entra: só 1 post normal por semana
    }

    public function test_semana_com_post_e_aviso_do_feriado_nao_chama_a_ia(): void
    {
        $this->travelTo(Carbon::parse('2026-11-30 09:00'));
        config(['ia.ideias_por_semana' => 1]);
        Http::fake();
        $this->ideia('2026-12-07 17:00', ['title' => 'Aviso: feriado de 8 de dezembro', 'source' => 'Tipo: aviso.']);
        $this->ideia('2026-12-09 10:00');

        $this->artisan('post:calendario-ia')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_aviso_da_ponte_na_semana_anterior_conta_para_o_feriado(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 09:00')); // semana seguinte: 30 nov a 6 dez (1 dez = feriado)
        config(['ia.ideias_por_semana' => 1]);
        Http::fake();
        // a ponte: o aviso sai na quinta antes (26 nov), fora da semana do feriado
        $this->ideia('2026-11-26 10:00', ['title' => 'Aviso: ponte de 30 de novembro', 'source' => 'Tipo: aviso.', 'status' => Post::PENDING]);
        $this->ideia('2026-12-02 10:00', ['status' => Post::PENDING]);

        $this->artisan('post:calendario-ia')->assertSuccessful();

        Http::assertNothingSent(); // não pede outro aviso para o 1 de dezembro
    }

    public function test_avisos_repetidos_para_o_mesmo_fecho(): void
    {
        $ponte = $this->ideia('2026-11-26 10:00', ['title' => 'Aviso: ponte de 30 de novembro', 'status' => Post::PENDING]);
        $outro = $this->ideia('2026-11-30 10:00', ['title' => 'Aviso: encerrados a 1 de dezembro', 'status' => Post::PENDING]);
        $natal = $this->ideia('2026-12-24 10:00', ['title' => 'Aviso: Encerramento por Natal', 'source' => 'Tipo: aviso.']);
        $anoNovo = $this->ideia('2026-12-28 18:00', ['title' => 'Aviso: Encerramento por Ano Novo', 'source' => 'Tipo: aviso.']);
        $this->ideia('2026-11-27 10:00'); // uma dica na mesma altura não é aviso

        $this->assertSame('2026-12-01', $ponte->feriadoDoAviso());
        $this->assertSame([$outro->id], $ponte->avisosRepetidos()->pluck('id')->all());
        $this->assertTrue($natal->avisosRepetidos()->isEmpty());   // Natal e Ano Novo: 4 dias de diferença, mas são fechos diferentes
        $this->assertTrue($anoNovo->avisosRepetidos()->isEmpty());

        $this->actingAs(User::factory()->create())->get(route('painel'))->assertOk()
            ->assertSee('Pode ser repetido')->assertSee('Aviso: encerrados a 1 de dezembro');

        // rejeitado deixa de contar
        $outro->update(['status' => Post::REJECTED]);
        $this->assertTrue($ponte->fresh()->avisosRepetidos()->isEmpty());
    }

    public function test_semana_cheia_nao_chama_a_ia(): void
    {
        Http::fake();
        foreach (['2026-11-10', '2026-11-11', '2026-11-12'] as $d) {
            $this->ideia("$d 10:00");
        }

        $this->artisan('post:calendario-ia')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_ao_fim_de_semana_so_com_o_botao(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07 11:00')); // sábado
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push($this->resposta(['ideias' => [['titulo' => 'Tema', 'tipo' => 'dica', 'notas' => '', 'quando' => '2026-11-10 10:00']]]))
            ->push($this->resposta([]))]);

        $this->artisan('post:calendario-ia')->assertSuccessful();
        Http::assertNothingSent();

        $this->actingAs(User::factory()->create())->post(route('calendario.propor'))->assertRedirect();
        $this->assertTrue(Cache::has(CalendarioIa::PEDIDO_PROPOR));

        $this->artisan('post:calendario-ia')->assertSuccessful();
        $this->assertSame(1, Post::count());
        $this->assertFalse(Cache::has(CalendarioIa::PEDIDO_PROPOR));
    }

    public function test_trocar_tema_e_feito_pela_ia(): void
    {
        config(['ia.ideias_por_semana' => 0]);
        $ideia = $this->ideia('2026-11-20 10:00', ['change_request' => 'Prefiro algo sobre UPS']);
        Http::fake(['ia.teste/*' => Http::sequence()
            ->push($this->resposta(['ideias' => [['titulo' => 'UPS Riello: energia sem falhas', 'tipo' => 'produto', 'notas' => 'Vantagens.', 'quando' => '2026-11-18 10:00']]]))
            ->push($this->resposta([]))]);

        $this->artisan('post:calendario-ia')->assertSuccessful();

        $ideia->refresh();
        $this->assertSame('UPS Riello: energia sem falhas', $ideia->title);
        $this->assertNull($ideia->change_request);
        $this->assertSame('2026-11-20 10:00', $ideia->scheduled_at->format('Y-m-d H:i'), 'a data não muda');
        $this->assertSame('auto_swapped', $ideia->reviews()->first()->action);
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][1]['content'] ?? '', 'Prefiro algo sobre UPS'));
    }

    public function test_cartao_da_ideia_diz_quando_a_ia_a_faz(): void
    {
        $this->ideia('2026-11-20 10:00');

        $this->actingAs(User::factory()->create())->get(route('painel', ['ver' => 'ideias']))
            ->assertSee('A IA faz este post sozinha')->assertSee('a 13 de novembro');
    }

    public function test_sem_chave_nao_faz_nada(): void
    {
        config(['ia.chave' => null]);
        Http::fake();
        $ideia = $this->ideia('2026-11-04 10:00');

        $this->artisan('post:calendario-ia')->assertSuccessful();

        $this->assertSame(Post::IDEA, $ideia->fresh()->status);
        Http::assertNothingSent();
    }
}
