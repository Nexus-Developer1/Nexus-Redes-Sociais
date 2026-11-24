<?php

namespace Tests\Feature;

use App\Mail\PostParaParceiros;
use App\Models\Parceiro;
use App\Models\ParceiroEnvio;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Posts também por email aos parceiros: lista, escolha em cada post, envio à hora marcada e só depois de aprovado. */
class ParceirosTest extends TestCase
{
    use RefreshDatabase;

    private function novoPost(array $attrs = []): Post
    {
        return Post::create(array_merge([
            'title' => 'UPS Riello',
            'caption' => "Proteja os seus equipamentos.\n\nFale connosco: nexus-solutions.pt\n\n#NEXUS #UPS #Energia",
            'networks' => ['Instagram', 'Facebook'],
            'scheduled_at' => now()->subMinutes(2),
            'status' => Post::APPROVED,
            'enviar_parceiros' => true,
            'design' => ['modelo' => 'produto', 'titulo' => "Proteja os seus\n*equipamentos*"],
        ], $attrs));
    }

    public function test_ler_lista_colada_do_excel_ou_csv(): void
    {
        [$lista, $ignoradas] = Parceiro::lerLista(
            "Nome;Empresa;Email\n"                          // cabeçalho: sem email, ignorado
            ."Ana Costa\tInfoPorto\tANA@infoporto.pt\n"      // colado do Excel (tabulações)
            ."bruno@redes.pt\n"                              // só o email
            ."Carla Dias <carla@x.pt>, Datalink\n"           // "Nome <email>"
            ."ana@infoporto.pt;Repetida\n"                   // repetida
            ."Duarte\tInfoPorto, Lda.\tduarte@ip.pt\n"        // vírgula dentro de uma coluna do Excel
        );

        $this->assertSame(1, $ignoradas);
        $this->assertCount(4, $lista);
        $this->assertSame(['nome' => 'Ana Costa', 'empresa' => 'InfoPorto', 'email' => 'ana@infoporto.pt'], $lista[0]);
        $this->assertSame('Bruno', $lista[1]['nome']);
        $this->assertSame(['nome' => 'Carla Dias', 'empresa' => 'Datalink', 'email' => 'carla@x.pt'], $lista[2]);
        $this->assertSame('InfoPorto, Lda.', $lista[3]['empresa']);
    }

    public function test_pagina_adicionar_importar_sair_e_remover(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('parceiros.store'), ['nome' => 'Ana', 'email' => 'Ana@Empresa.pt'])->assertSessionHas('ok');
        $this->actingAs($user)->post(route('parceiros.store'), ['nome' => 'Outra', 'email' => 'ana@empresa.pt'])->assertSessionHasErrors('email');

        $ana = Parceiro::firstWhere('email', 'ana@empresa.pt');
        $this->actingAs($user)->post(route('parceiros.estado', $ana));
        $this->assertFalse($ana->fresh()->ativo);

        // Importar uma lista com a Ana outra vez: não volta a receber (pediu para sair).
        $this->actingAs($user)->post(route('parceiros.importar'), ['lista' => "ana@empresa.pt\nBruno\tRedes\tbruno@redes.pt"])
            ->assertSessionHas('ok', '1 parceiro adicionado, 1 já estava na lista.');
        $this->assertFalse($ana->fresh()->ativo);
        $this->assertSame(1, Parceiro::quantosAtivos());

        $this->actingAs($user)->get(route('parceiros'))->assertOk()->assertSee('bruno@redes.pt')->assertSee('Deixou de receber');
        $this->actingAs($user)->post(route('parceiros.destroy', $ana))->assertSessionHas('ok');
        $this->assertNull($ana->fresh());
    }

    public function test_envia_a_hora_a_cada_parceiro_ativo_uma_so_vez(): void
    {
        Mail::fake();
        $ana = Parceiro::create(['nome' => 'Ana Costa', 'email' => 'ana@x.pt']);
        $bruno = Parceiro::create(['nome' => 'Bruno', 'email' => 'bruno@x.pt']);
        Parceiro::create(['nome' => 'Saiu', 'email' => 'saiu@x.pt', 'ativo' => false]);
        $post = $this->novoPost();

        $this->artisan('post:enviar-parceiros')->assertSuccessful();
        $this->artisan('post:enviar-parceiros')->assertSuccessful(); // segunda volta: nada repetido

        Mail::assertSent(PostParaParceiros::class, 2);
        Mail::assertSent(PostParaParceiros::class, fn ($m) => $m->hasTo('ana@x.pt') && ! $m->hasTo('bruno@x.pt')); // um email por pessoa
        Mail::assertNotSent(PostParaParceiros::class, fn ($m) => $m->hasTo('saiu@x.pt'));
        $this->assertNotNull($post->fresh()->parceiros_enviado_em);
        $this->assertSame(2, ParceiroEnvio::whereNotNull('enviado_em')->count());
        $this->assertDatabaseHas('post_reviews', ['post_id' => $post->id, 'action' => 'partners_sent', 'comment' => '2 parceiros']);
    }

    public function test_nao_envia_sem_aprovacao_antes_da_hora_nem_muito_depois(): void
    {
        Mail::fake();
        Parceiro::create(['nome' => 'Ana', 'email' => 'ana@x.pt']);
        $this->novoPost(['status' => Post::PENDING]);                              // não aprovado
        $this->novoPost(['scheduled_at' => now()->addHour()]);                     // ainda não é a hora
        $this->novoPost(['scheduled_at' => now()->subHours(7)]);                   // passou demasiado tempo
        $this->novoPost(['enviar_parceiros' => false]);                            // não marcado

        $this->artisan('post:enviar-parceiros')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_email_leva_a_imagem_dentro_sem_hashtags_e_com_como_sair(): void
    {
        $post = $this->novoPost(['image_path' => 'modelos/logo-nexus.png']);
        $mail = new PostParaParceiros($post, new Parceiro(['nome' => 'Ana Costa', 'email' => 'ana@x.pt']));

        $mail->assertHasSubject('Proteja os seus equipamentos');
        $html = $mail->render();
        $this->assertStringContainsString('Olá Ana,', $html);
        $this->assertMatchesRegularExpression('/src="(cid:|data:image)/', $html); // imagem dentro do email (cid: ao enviar)
        $this->assertStringNotContainsString(url('modelos/logo-nexus.png'), $html); // nunca o endereço interno
        $this->assertStringContainsString('Fale connosco: nexus-solutions.pt', $html);
        $this->assertStringNotContainsString('#UPS', $html);
        $this->assertStringContainsString('mailto:', $html);
        // modelo da newsletter da NEXUS: produtos, "Torne-se um parceiro" e redes
        $this->assertStringContainsString('TORNE-SE UM PARCEIRO NEXUS', $html);
        $this->assertStringContainsString('https://nexus-solutions.pt/programa-parceiros-nexus/', $html);
        $this->assertStringContainsString('https://configurador.nexus-solutions.pt/', $html);
        $this->assertStringContainsString('linkedin.com/company/nexussolutions', $html);
    }

    public function test_marcar_so_antes_de_aprovar_e_nao_elimina_depois_de_enviado(): void
    {
        $user = User::factory()->create();
        $pendente = $this->novoPost(['status' => Post::PENDING, 'enviar_parceiros' => false]);

        $this->actingAs($user)->post(route('posts.partners', $pendente), ['enviar' => 1])->assertSessionHas('ok');
        $this->assertTrue($pendente->fresh()->enviar_parceiros);
        $this->actingAs($user)->get(route('painel'))->assertSee('✉ Parceiros');

        $aprovado = $this->novoPost(['enviar_parceiros' => false]);
        $this->actingAs($user)->post(route('posts.partners', $aprovado), ['enviar' => 1])->assertStatus(409);
        $this->assertFalse($aprovado->fresh()->enviar_parceiros);

        // Já enviado a um parceiro: fica no histórico, não se elimina.
        $rejeitado = $this->novoPost(['status' => Post::REJECTED]);
        $parceiro = Parceiro::create(['nome' => 'Ana', 'email' => 'ana@x.pt']);
        ParceiroEnvio::create(['post_id' => $rejeitado->id, 'parceiro_id' => $parceiro->id, 'enviado_em' => now()]);
        $this->actingAs($user)->post(route('posts.destroy', $rejeitado))->assertSessionHas('erro');
        $this->assertNotNull($rejeitado->fresh());
    }

    public function test_email_de_teste_vai_so_para_quem_pede(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'eu@nexus.pt']);
        Parceiro::create(['nome' => 'Ana', 'email' => 'ana@x.pt']);
        $post = $this->novoPost(['status' => Post::PENDING, 'image_path' => 'modelos/logo-nexus.png']);

        $this->actingAs($user)->post(route('posts.partnersTest', $post))->assertSessionHas('ok');

        Mail::assertSent(PostParaParceiros::class, fn ($m) => $m->hasTo('eu@nexus.pt') && $m->teste);
        Mail::assertNotSent(PostParaParceiros::class, fn ($m) => $m->hasTo('ana@x.pt'));
        $this->assertSame(Post::PENDING, $post->fresh()->status);
    }

    public function test_aprovador_ve_no_email_que_tambem_vai_aos_parceiros(): void
    {
        Parceiro::create(['nome' => 'Ana', 'email' => 'ana@x.pt']);
        $aprovador = User::factory()->create();
        $post = $this->novoPost(['status' => Post::PENDING]);

        $html = (string) (new \App\Notifications\PostParaAprovar($post))->toMail($aprovador)->render();

        $this->assertStringContainsString('+ email a 1 parceiro', $html);
    }
}
