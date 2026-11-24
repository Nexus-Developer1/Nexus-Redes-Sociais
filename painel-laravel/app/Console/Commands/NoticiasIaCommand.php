<?php

namespace App\Console\Commands;

use App\Services\NoticiasIa;
use App\Services\PostWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O agente das notícias de IA: recolhe as notícias dos feeds e a IA faz o resumo (página "Notícias de IA").
 * Corre à segunda e à quinta-feira às 08:00 e, com --pedido, a cada minuto só se alguém carregou em "Recolher agora".
 */
class NoticiasIaCommand extends Command
{
    protected $signature = 'ia:noticias {--pedido : Só trabalha se alguém pediu no painel ("Recolher agora")}';

    protected $description = 'Recolhe as notícias de IA dos feeds e faz o resumo com a IA';

    /** Pedido do botão "Recolher agora". */
    public const PEDIDO = 'noticias-ia:pedido';

    /** Enquanto o agente trabalha (a página mostra "a recolher…"). */
    public const A_TRABALHAR = 'noticias-ia:a-trabalhar';

    public function handle(NoticiasIa $agente, PostWriter $escritor): int
    {
        if ($this->option('pedido') && ! Cache::pull(self::PEDIDO)) {
            return self::SUCCESS;
        }
        $trinco = Cache::lock('ia:noticias', 15 * 60);
        if (! $trinco->get()) {
            $this->line('O agente já está a trabalhar.');

            return self::SUCCESS;
        }
        Cache::put(self::A_TRABALHAR, 1, now()->addMinutes(10));
        try {
            $r = $agente->recolher();
            $this->info("Notícias novas: {$r['novas']}");
            foreach ($r['erros'] as $fonte => $erro) {
                $this->warn("{$fonte}: {$erro}");
            }
            if (! $escritor->configurado()) {
                $this->line('IA não configurada (IA_CHAVE): notícias guardadas, sem resumo.');

                return self::SUCCESS;
            }
            try {
                $resumo = $agente->resumir($escritor);
                $this->info($resumo ? "Resumo #{$resumo->id}: {$resumo->conteudo['titulo']} ({$resumo->n_noticias} notícias)" : 'Sem notícias novas para resumir.');
            } catch (Throwable $e) {
                // ex.: limite do plano gratuito: as notícias ficam guardadas e entram no próximo resumo
                Log::warning('Resumo das notícias de IA falhou: '.$e->getMessage());
                $this->warn('O resumo falhou: '.$e->getMessage());
            }
        } finally {
            Cache::forget(self::A_TRABALHAR);
            $trinco->release();
        }

        return self::SUCCESS;
    }
}
