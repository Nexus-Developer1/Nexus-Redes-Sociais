<?php

namespace App\Console\Commands;

use App\Mail\PostParaParceiros;
use App\Models\Parceiro;
use App\Models\ParceiroEnvio;
use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envia por email aos parceiros os posts marcados com "Enviar também aos parceiros", à hora marcada do post
 * (a mesma da publicação). Só posts APROVADOS por uma pessoa; nada sai mais de 6 h depois da hora.
 * Um email por parceiro, registado em parceiro_envios: nunca sai duas vezes para a mesma pessoa,
 * e um envio interrompido continua na volta seguinte. Corre a cada 5 minutos (routes/console.php).
 */
class EnviarParceiros extends Command
{
    protected $signature = 'post:enviar-parceiros {--simular : Mostra o que seria enviado, sem enviar}';

    protected $description = 'Envia por email aos parceiros os posts aprovados marcados para isso, à hora marcada';

    /** Emails por volta (de 5 em 5 minutos). O Microsoft 365 aceita até 30 por minuto de cada caixa. */
    public const MAX_POR_VOLTA = 120;

    /** Tentativas por parceiro antes de desistir dele (fica o erro no registo). */
    public const MAX_TENTATIVAS = 3;

    public function handle(): int
    {
        if (! config('painel.emails_ligados')) {
            $this->line('Emails desligados (EMAILS_LIGADOS=false): nada enviado aos parceiros.');

            return self::SUCCESS; // nada fica marcado como enviado
        }
        $trinco = Cache::lock('post:enviar-parceiros', 30 * 60);
        if (! $trinco->get()) {
            $this->line('Já há um envio a decorrer.');

            return self::SUCCESS;
        }
        try {
            $this->enviarTudo();
        } finally {
            $trinco->release();
        }

        return self::SUCCESS;
    }

    private function enviarTudo(): void
    {
        $posts = Post::where('enviar_parceiros', true)
            ->whereNull('parceiros_enviado_em')
            ->whereIn('status', [Post::APPROVED, Post::PUBLISHED])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->where('scheduled_at', '>=', now()->subHours(PublicarPosts::MAX_ATRASO_HORAS))
            ->orderBy('scheduled_at')
            ->get();
        if ($posts->isEmpty()) {
            $this->line('Nada para enviar aos parceiros.');

            return;
        }

        $restam = self::MAX_POR_VOLTA;
        $pausa = (int) config('painel.parceiros_pausa_ms', 2100);
        foreach ($posts as $post) {
            // Parceiros ativos que ainda não receberam este post e ainda não falharam demasiadas vezes.
            $porEnviar = Parceiro::ativos()
                ->whereDoesntHave('envios', fn ($q) => $q->where('post_id', $post->id)
                    ->where(fn ($q) => $q->whereNotNull('enviado_em')->orWhere('tentativas', '>=', self::MAX_TENTATIVAS)))
                ->orderBy('id')
                ->get();

            if ($this->option('simular')) {
                $this->line("Seria enviado: #{$post->id} {$post->title} → {$porEnviar->count()} parceiros");

                continue;
            }

            foreach ($porEnviar as $parceiro) {
                if ($restam-- <= 0) {
                    $this->line('Limite desta volta atingido: o resto segue daqui a 5 minutos.');

                    return;
                }
                $envio = ParceiroEnvio::firstOrCreate(['post_id' => $post->id, 'parceiro_id' => $parceiro->id]);
                try {
                    Mail::to($parceiro->email, $parceiro->nome)->send(new PostParaParceiros($post, $parceiro));
                    $envio->update(['enviado_em' => now(), 'erro' => null]);
                } catch (Throwable $e) {
                    report($e);
                    $envio->update(['tentativas' => $envio->tentativas + 1, 'erro' => mb_substr($e->getMessage(), 0, 300)]);
                    $this->error("#{$post->id} → {$parceiro->email}: {$e->getMessage()}");
                }
                if ($pausa > 0) {
                    usleep($pausa * 1000);
                }
            }

            $this->fecharSeAcabou($post);
        }
    }

    /** O envio acaba quando todos os parceiros ativos receberam (ou falharam 3 vezes): fica a hora e o histórico. */
    private function fecharSeAcabou(Post $post): void
    {
        $falta = Parceiro::ativos()
            ->whereDoesntHave('envios', fn ($q) => $q->where('post_id', $post->id)
                ->where(fn ($q) => $q->whereNotNull('enviado_em')->orWhere('tentativas', '>=', self::MAX_TENTATIVAS)))
            ->exists();
        if ($falta) {
            return;
        }
        $enviados = $post->envios()->whereNotNull('enviado_em')->count();
        $falhados = $post->envios()->whereNull('enviado_em')->count();
        if ($enviados + $falhados === 0) {
            $this->warn("#{$post->id}: não há parceiros ativos na lista. Nada enviado.");

            return; // sem lista não se dá por enviado: quem for adicionado ainda dentro da hora recebe
        }
        // sem mexer no updated_at (é a "versão" dos links do email de aprovação)
        Post::whereKey($post->id)->toBase()->update(['parceiros_enviado_em' => now()]);
        $post->reviews()->create(['action' => 'partners_sent',
            'comment' => $enviados.' '.($enviados === 1 ? 'parceiro' : 'parceiros').($falhados ? ", {$falhados} falharam" : '')]);
        $this->info("#{$post->id} {$post->title}: enviado a {$enviados} parceiros".($falhados ? " ({$falhados} falharam)" : '').'.');
    }
}
