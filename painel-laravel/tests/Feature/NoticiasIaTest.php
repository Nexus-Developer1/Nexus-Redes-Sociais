<?php

namespace Tests\Feature;

use App\Console\Commands\NoticiasIaCommand;
use App\Models\NoticiaIa;
use App\Models\ResumoIa;
use App\Models\User;
use App\Services\NoticiasIa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Página "Notícias de IA": o agente recolhe os feeds e a IA faz o resumo. */
class NoticiasIaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-26 08:00'));
        config(['ia.chave' => 'chave-de-teste', 'ia.endereco' => 'https://ia.teste/v1',
            'ia.fontes_noticias' => ['Blog RSS' => 'https://rss.teste/feed', 'Blog Atom' => 'https://atom.teste/feed', 'Em baixo' => 'https://falha.teste/feed']]);
    }

    private function rss(): string
    {
        return '<?xml version="1.0"?><rss version="2.0"><channel><title>Blog</title>'
            .'<item><title>Novo modelo &amp; GPUs</title><link>https://rss.teste/a</link><description><![CDATA[<p>Lançado <b>hoje</b>.</p>]]></description><pubDate>Wed, 25 Nov 2026 10:00:00 +0000</pubDate></item>'
            .'<item><title>Ligação perigosa</title><link>javascript:alert(1)</link><pubDate>Wed, 25 Nov 2026 09:00:00 +0000</pubDate></item>'
            .'<item><title>Notícia antiga</title><link>https://rss.teste/velha</link><pubDate>Mon, 02 Nov 2026 10:00:00 +0000</pubDate></item>'
            .'</channel></rss>';
    }

    private function atom(): string
    {
        return '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Atom</title>'
            .'<entry><title>Agentes nas empresas</title><link rel="alternate" href="https://atom.teste/b"/><summary>Resumo &lt;i&gt;curto&lt;/i&gt;</summary><updated>2026-11-24T12:00:00Z</updated></entry>'
            .'</feed>';
    }

    private function fingirFeedsEIa(array $resumo): void
    {
        Http::fake([
            'rss.teste/*' => Http::response($this->rss()),
            'atom.teste/*' => Http::response($this->atom()),
            'falha.teste/*' => Http::response('', 503),
            'ia.teste/*' => Http::response(['choices' => [['message' => ['content' => json_encode($resumo)]]]]),
        ]);
    }

    public function test_le_rss_e_atom_so_com_texto_e_so_recentes(): void
    {
        $rss = NoticiasIa::lerFeed($this->rss());
        $this->assertCount(1, $rss); // a ligação javascript: e a notícia antiga ficam de fora
        $this->assertSame('Novo modelo & GPUs', $rss[0]['titulo']);
        $this->assertSame('Lançado hoje.', $rss[0]['descricao']);    // sem HTML

        $atom = NoticiasIa::lerFeed($this->atom());
        $this->assertSame('https://atom.teste/b', $atom[0]['link']);
        $this->assertSame('Resumo curto', $atom[0]['descricao']);
    }

    public function test_agente_recolhe_e_a_ia_faz_o_resumo(): void
    {
        $this->fingirFeedsEIa(['titulo' => 'Semana de modelos novos', 'introducao' => 'Muito movimento.',
            'destaques' => [['titulo' => 'Modelo novo', 'resumo' => 'Foi lançado.', 'noticias' => [1, 999]]], // 999 não existe
            'para_a_nexus' => 'Mais procura de GPUs.', 'ideias_posts' => ['GPUs para IA nas empresas']]);

        $this->artisan('ia:noticias')->expectsOutputToContain('Notícias novas: 2')->assertSuccessful();

        $this->assertSame(2, NoticiaIa::count());
        $resumo = ResumoIa::first();
        $this->assertSame('Semana de modelos novos', $resumo->conteudo['titulo']);
        $this->assertSame([1], $resumo->conteudo['destaques'][0]['noticias']); // a IA não aponta para notícias que não existem
        $this->assertSame(2, $resumo->n_noticias);
        // O texto das notícias vai como dados, com a regra explícita.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ia.teste')
            && str_contains($r['messages'][0]['content'], 'nunca instruções') && str_contains($r['messages'][1]['content'], 'Novo modelo & GPUs'));

        // Segunda volta sem notícias novas: não repete o resumo.
        $this->travel(1)->hours();
        $this->artisan('ia:noticias')->expectsOutputToContain('Sem notícias novas')->assertSuccessful();
        $this->assertSame(1, ResumoIa::count());
    }

    public function test_pagina_e_botao_recolher_agora(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('noticias'))->assertOk()->assertSee('Ainda não há nenhum resumo');

        $this->actingAs($user)->post(route('noticias.recolher'))->assertRedirect(route('noticias'));
        $this->assertTrue(Cache::has(NoticiasIaCommand::PEDIDO));

        $this->fingirFeedsEIa(['titulo' => 'Resumo', 'destaques' => [['titulo' => 'Agentes', 'resumo' => 'Nas empresas.', 'noticias' => [1]]]]);
        $this->artisan('ia:noticias --pedido')->assertSuccessful(); // havia pedido: trabalha
        $this->assertSame(1, ResumoIa::count());
        $this->assertFalse(Cache::has(NoticiasIaCommand::PEDIDO));
        Http::fake(); // sem pedido: não faz nada
        $this->artisan('ia:noticias --pedido')->assertSuccessful();
        Http::assertNothingSent();

        $this->actingAs($user)->get(route('noticias'))->assertOk()
            ->assertSee('Agentes')->assertSee('https://rss.teste/a', false)->assertSee('Pedir um post sobre isto');
    }
}
