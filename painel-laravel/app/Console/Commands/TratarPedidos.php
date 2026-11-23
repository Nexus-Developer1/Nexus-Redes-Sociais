<?php

namespace App\Console\Commands;

use App\Models\ImagemBiblioteca;
use App\Models\Post;
use App\Models\PostRequest;
use App\Notifications\PostParaAprovar;
use App\Services\BancoFotos;
use App\Services\PostRenderer;
use App\Services\PostWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Trata sozinho do trabalho que antes esperava pelo Claude (corre a cada minuto no servidor):
 *  - pedidos novos de "Pedir um post"  → a IA escreve o post, o servidor gera a imagem, vai para "Para aprovar";
 *  - "Pedir alteração" em posts feitos com os modelos → a IA refaz os textos e o post volta à fila.
 * Nunca aprova nem publica. Sem IA configurada não faz nada.
 */
class TratarPedidos extends Command
{
    protected $signature = 'post:tratar-pedidos {--max=3 : Máximo de pedidos/alterações por execução}';

    protected $description = 'A IA cria os posts pedidos no painel e refaz os que têm alteração pedida';

    public function handle(PostWriter $escritor, PostRenderer $renderer): int
    {
        if (! $escritor->configurado()) {
            $this->line('IA não configurada (IA_CHAVE): nada a fazer.');

            return self::SUCCESS;
        }

        $this->soltarPresos();
        $restam = (int) $this->option('max');

        foreach (PostRequest::where('status', 'new')->oldest()->limit($restam)->get() as $pedido) {
            $restam--;
            $this->criar($pedido, $escritor, $renderer);
        }

        if ($restam > 0) {
            $alteracoes = Post::where('status', Post::REVISING)
                ->whereNotNull('design')->whereNotNull('change_request')
                ->oldest('updated_at')->limit($restam)->get();
            foreach ($alteracoes as $post) {
                $this->rever($post, $escritor, $renderer);
            }
        }

        return self::SUCCESS;
    }

    private function criar(PostRequest $pedido, PostWriter $escritor, PostRenderer $renderer): void
    {
        $pedido->update(['status' => 'in_progress', 'error' => null]);
        $post = null;
        // Pedido feito a partir de uma ideia do calendário ("A IA faz este post"): o post é essa ideia.
        $ideia = $pedido->post_id ? Post::whereKey($pedido->post_id)->whereIn('status', [Post::IDEA, Post::CREATING])->first() : null;

        try {
            $r = $escritor->criar($pedido->topic, $pedido->publish_at, (bool) $pedido->carrossel);

            $dados = [
                'title' => $r['titulo_interno'],
                'caption' => $r['legenda'],
                'networks' => Post::comStories($r['redes']), // posts novos do Instagram vão também para os Stories
                'scheduled_at' => $r['publicar_em'],
                'status' => Post::CREATING,
                'source' => ($ideia ? 'Ideia do calendário feita pela IA' : 'Pedido de '.($pedido->user?->name ?? 'alguém'))
                    .': “'.$pedido->topic.'”'
                    .($pedido->carrossel && empty($r['design']['carrossel']) ? ' · Foi pedido em carrossel, mas a IA não fez os slides: use “Carrossel” no Editar.' : ''),
                'design' => ['formato' => $r['design']['formato'] ?? '4:5'],
            ];
            if ($ideia) {
                // A IA demorou: confirmar que ninguém descartou a ideia entretanto.
                if (! in_array($ideia->refresh()->status, [Post::IDEA, Post::CREATING], true)) {
                    $pedido->update(['status' => 'failed', 'error' => 'A ideia foi descartada ou mudou enquanto a IA trabalhava.']);

                    return;
                }
                $ideia->update($dados);
                $post = $ideia;
            } else {
                $post = Post::create($dados);
            }
            $design = $r['design'];
            // Produto e parceiro escolhidos pela IA na biblioteca: copia as imagens oficiais para o post.
            foreach (['produto' => $r['produto_biblioteca'] ?? 0, 'parceiro' => $r['parceiro_biblioteca'] ?? 0] as $campo => $id) {
                $img = $id ? ImagemBiblioteca::where('tipo', ImagemBiblioteca::PARA_CAMPO[$campo])->find($id) : null;
                if ($img) {
                    $design[$campo] = $img->copiarPara($post);
                }
            }
            if (($design['modelo'] ?? '') === 'montra' && empty($design['produto'])) {
                $design['modelo'] = 'produto'; // sem a foto do produto, o "produto em destaque" não faz sentido
            }
            // Com a foto do produto, o fundo fica limpo; senão procura-se uma foto de fundo.
            if (empty($design['produto'])) {
                $design = $this->comFoto($post, $design, $r['pesquisa_imagem'] ?? '');
            }
            $this->montar($post, $design, $renderer);
            $this->paraAprovacao($post, 'auto_created');

            $pedido->update(['status' => 'done', 'post_id' => $post->id]);
            $this->info("Pedido #{$pedido->id} → post #{$post->id}: {$post->title}");
        } catch (Throwable $e) {
            report($e);
            if ($ideia) {
                // a ideia fica como estava (se ninguém a descartou entretanto)
                Post::whereKey($ideia->id)->where('status', Post::CREATING)->update(['status' => Post::IDEA]);
            } else {
                $post?->delete();
            }
            if (str_contains($e->getMessage(), 'erro 429')) {
                // Limite do plano gratuito: o pedido volta à fila e tenta-se de novo no minuto seguinte.
                $pedido->update(['status' => 'new', 'error' => 'Limite da IA atingido; volta a tentar sozinho.']);
            } else {
                $pedido->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            }
            $this->error("Pedido #{$pedido->id} falhou: {$e->getMessage()}");
        }
    }

    private function rever(Post $post, PostWriter $escritor, PostRenderer $renderer): void
    {
        // Se falhou há pouco com este mesmo pedido, espera uma hora antes de tentar de novo.
        $chave = 'ia-falhou:post:'.$post->id.':'.md5((string) $post->change_request);
        if (Cache::has($chave)) {
            return;
        }

        $pedido = (string) $post->change_request;
        $reservado = false;
        try {
            $r = $escritor->rever($post, $pedido);

            // A IA demora até um minuto e meio. Entretanto alguém pode ter desfeito o pedido ou aprovado o post:
            // só se grava se o post ainda estiver à espera DESTA alteração. Fica "Em criação" enquanto a imagem
            // é feita, para ninguém o aprovar a meio (aprovar só funciona em "À espera de aprovação").
            $reservado = Post::whereKey($post->id)->where('status', Post::REVISING)->where('change_request', $pedido)
                ->update(['status' => Post::CREATING, 'updated_at' => now()]) === 1;
            if (! $reservado) {
                $this->line("Post #{$post->id}: o pedido de alteração mudou entretanto; nada gravado.");

                return;
            }
            $post->refresh();

            // A data e as redes só mudam se a pessoa pediu isso; senão ficam como estavam.
            $post->fill([
                'title' => $r['titulo_interno'],
                'caption' => $r['legenda'],
                'networks' => self::pedeRedes($pedido) ? Post::comStories($r['redes'], $post->temStories()) : $post->networks,
                'scheduled_at' => self::pedeData($pedido) ? $r['publicar_em'] : $post->scheduled_at,
            ]);
            // As imagens que alguém pôs no post (fundo, foto, parceiro) mantêm-se.
            $design = $r['design'] + array_intersect_key($post->design ?? [], array_flip(['foto', 'fundo', 'produto', 'parceiro', 'enquadramento', 'tom']));
            $this->montar($post, $design, $renderer);
            $this->paraAprovacao($post, 'auto_revised');
            $this->info("Post #{$post->id} refeito: {$post->title}");
        } catch (Throwable $e) {
            report($e);
            Cache::put($chave, true, now()->addHour());
            if ($reservado) {
                // Volta a "Alteração pedida" para se ver no painel e tentar de novo daqui a uma hora.
                Post::whereKey($post->id)->where('status', Post::CREATING)->update(['status' => Post::REVISING]);
            }
            $post->reviews()->create(['action' => 'ai_failed', 'comment' => mb_substr($e->getMessage(), 0, 500)]);
            $this->error("Post #{$post->id}: a alteração falhou: {$e->getMessage()}");
        }
    }

    /**
     * Trabalho interrompido (o servidor reiniciou ou a instalação correu a meio): um pedido "a fazer" há mais de
     * 20 minutos já não vai acabar. Fica como falhado, com o botão "Repetir", e a ideia volta a ser ideia.
     * Um post refeito que ficou "Em criação" volta a "Alteração pedida".
     */
    private function soltarPresos(): void
    {
        $limite = now()->subMinutes(20);
        foreach (PostRequest::where('status', 'in_progress')->where('updated_at', '<', $limite)->get() as $pedido) {
            $pedido->update(['status' => 'failed', 'error' => 'O trabalho da IA foi interrompido. Carregue em “Repetir”.']);
            if ($pedido->post_id) {
                Post::whereKey($pedido->post_id)->where('status', Post::CREATING)->whereNull('image_path')
                    ->update(['status' => Post::IDEA]);
            }
        }
        Post::where('status', Post::CREATING)->whereNotNull('change_request')->whereNotNull('design')
            ->where('updated_at', '<', $limite)->update(['status' => Post::REVISING]);
    }

    /** O pedido de alteração fala da data ou da hora? (senão a IA não mexe na data) */
    public static function pedeData(string $pedido): bool
    {
        return (bool) preg_match('/\b(data|dia|hora|horas|amanh[ãa]|hoje|semana|segunda|ter[çc]a|quarta|quinta|sexta|s[áa]bado|domingo|'
            .'janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro|adiar|antecipar|\d{1,2}h|\d{1,2}[\/.-]\d{1,2})\b/iu', $pedido);
    }

    /** O pedido de alteração fala das redes? (senão ficam as que estavam) */
    public static function pedeRedes(string $pedido): bool
    {
        return (bool) preg_match('/\b(instagram|facebook|linkedin|stories|story|redes?)\b/iu', $pedido);
    }

    /**
     * Com o Pexels ligado, procura uma foto de fundo para o tema. Se falhar, o post segue sem foto
     * (fundo verde da marca); nunca impede o post de chegar à fila.
     */
    private function comFoto(Post $post, array $design, string $pesquisa): array
    {
        $tom = ($design['modelo'] ?? '') === 'frase' ? 'forte' : 'suave';

        // 1.º as fotos da biblioteca da NEXUS (equipa, instalações…), se alguma servir para o tema.
        // (fotos já usadas noutros posts ficam de fora: a mesma foto não se repete em posts diferentes)
        $nossas = ImagemBiblioteca::where('tipo', 'foto')->whereNotIn('id', Post::fotosEmUso($post->id)['biblioteca'])
            ->latest()->limit(40)->get();
        if ($nossas->isNotEmpty()) {
            try {
                $temaBib = trim($post->title.' — '.str_replace(['*', "\n"], ['', ' '], (string) ($design['titulo'] ?? '')));
                $id = app(PostWriter::class)->escolherFoto($temaBib,
                    $nossas->map(fn ($i) => ['id' => $i->id, 'alt' => $i->descricao])->all());
                if ($id && ($img = $nossas->firstWhere('id', $id))) {
                    $design['foto'] = $img->copiarPara($post);
                    $design['tom'] = $tom;
                    unset($design['icone']);
                    $post->source = trim($post->source.' · Foto da biblioteca NEXUS');

                    return $design;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        // 2.º o banco de fotos grátis.
        $pexels = app(BancoFotos::class);
        if ($pesquisa === '' || ! $pexels->configurado()) {
            return $design;
        }

        try {
            // A IA vê as candidatas e escolhe a que serve (ou nenhuma): nunca fica a primeira à sorte.
            $fotos = $pexels->procurarNovas($pesquisa, $design['formato'] ?? '4:5', 12, $post);
            $tema = trim($post->title.' — '.str_replace(['*', "\n"], ['', ' '], (string) ($design['titulo'] ?? '')));
            $escolhida = app(PostWriter::class)->escolherFoto($tema, $fotos);
            if (! $escolhida) {
                $this->line("Post #{$post->id}: nenhuma foto servia para \"{$pesquisa}\"; fica o fundo verde.");

                return $design;
            }
            $foto = $pexels->descarregar($escolhida, $post);
            $design['foto'] = $foto['nome'];
            $design['pesquisa'] = $pesquisa; // para o botão "Outra foto"
            $design['fotos_usadas'] = [$escolhida];
            $design['tom'] = ($design['modelo'] ?? '') === 'frase' ? 'forte' : 'suave';
            unset($design['icone']); // o ícone tapava a foto
            $post->source = trim($post->source.' · Foto: '.$foto['fotografo'].' ('.$pexels->nome().')');
        } catch (Throwable $e) {
            report($e);
            $this->warn("Post #{$post->id}: sem foto do banco de fotos ({$e->getMessage()}).");
        }

        return $design;
    }

    private function montar(Post $post, array $design, PostRenderer $renderer): void
    {
        $post->design = PostRenderer::limparDesign($design, $post);
        $post->image_alt = trim(str_replace(['*', "\n"], ['', ' '], $post->design['titulo'] ?? $post->title));
        $post->save();

        $post->guardarImagens($renderer->render($post));
    }

    private function paraAprovacao(Post $post, string $acao): void
    {
        // Só passa a "À espera" se ainda estiver em criação (alguém pode ter descartado a ideia entretanto).
        $mudou = Post::whereKey($post->id)->where('status', Post::CREATING)
            ->update(['status' => Post::PENDING, 'change_request' => null, 'updated_at' => now()]);
        $post->refresh();
        if ($mudou !== 1) {
            return;
        }
        $post->reviews()->create(['user_id' => null, 'action' => $acao]);

        try {
            PostParaAprovar::enviar($post);
        } catch (Throwable $e) {
            report($e); // o post fica na fila na mesma
        }
    }
}
