<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Notifications\PostParaAprovar;
use Illuminate\Console\Command;
use Throwable;

/**
 * Lembrete por email: um post que continua "À espera de aprovação" quando faltam menos de 48 horas para a data
 * não sai se ninguém o aprovar. Envia um lembrete (com os mesmos botões do primeiro email) uma só vez por post.
 * Corre de hora a hora, só entre as 8h e as 20h (routes/console.php).
 */
class LembrarAprovacoes extends Command
{
    protected $signature = 'post:lembrar {--horas=48 : Quantas horas antes da data se lembra}';

    protected $description = 'Lembra quem aprova dos posts ainda por aprovar perto da data';

    public function handle(): int
    {
        if (! config('painel.emails_ligados')) {
            $this->line('Emails desligados (EMAILS_LIGADOS=false): nenhum lembrete enviado.');

            return self::SUCCESS; // nada fica marcado: ao ligar, os lembretes seguem normalmente
        }
        $posts = Post::where('status', Post::PENDING)
            // um lembrete por versão: se o post mudou depois do lembrete, a nova versão também é lembrada
            ->where(fn ($q) => $q->whereNull('lembrete_em')->orWhereColumn('lembrete_em', '<', 'updated_at'))
            ->whereBetween('scheduled_at', [now(), now()->addHours((int) $this->option('horas'))])
            // o primeiro email acabou de chegar? não vale a pena lembrar já
            ->where('updated_at', '<', now()->subHours(12))
            ->orderBy('scheduled_at')
            ->get();

        foreach ($posts as $post) {
            try {
                PostParaAprovar::enviar($post, null, true);
                // sem mexer no updated_at: é a "versão" dos links do email que acabou de sair
                Post::whereKey($post->id)->toBase()->update(['lembrete_em' => now()]);
                $post->reviews()->create(['action' => 'reminder_sent']);
                $this->info("Lembrete enviado: #{$post->id} {$post->title}");
            } catch (Throwable $e) {
                report($e);
                $this->error("#{$post->id}: o lembrete falhou: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
