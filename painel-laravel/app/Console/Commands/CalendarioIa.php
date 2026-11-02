<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostRequest;
use App\Services\DatasEspeciais;
use App\Services\PostWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O calendário anda sozinho (corre a cada minuto no servidor; sem IA configurada não faz nada):
 *  1. "Trocar tema" numa ideia → a IA propõe outro tema para essa data, a pensar no que foi pedido;
 *  2. cada ideia é feita pela IA uns dias antes da data (config ia.ideias_dias_antes), sem ninguém carregar no botão;
 *  3. de segunda a sexta, se ainda não o fez, propõe as ideias da semana seguinte (até ia.ideias_por_semana),
 *     a pensar nos feriados, nos dias especiais e nos produtos. O botão do calendário pede-o logo.
 * Nunca aprova nem publica: os posts feitos vão para "Para aprovar", como os outros.
 */
class CalendarioIa extends Command
{
    protected $signature = 'post:calendario-ia {--propor : Propõe já as ideias da próxima semana}';

    protected $description = 'A IA troca temas, faz as ideias perto da data e propõe as ideias da semana seguinte';

    /** Pedido feito pelo botão "Propor ideias" do calendário. */
    public const PEDIDO_PROPOR = 'calendario-ia:propor-agora';

    public function handle(PostWriter $escritor): int
    {
        if (! $escritor->configurado()) {
            $this->line('IA não configurada (IA_CHAVE): nada a fazer.');

            return self::SUCCESS;
        }

        $this->trocarTemas($escritor);
        $this->fazerIdeias();
        $this->proporSemana($escritor, $this->option('propor') || Cache::pull(self::PEDIDO_PROPOR));

        return self::SUCCESS;
    }

    /** 1. Ideias com "Trocar tema": a IA propõe outro tema para a mesma data. */
    private function trocarTemas(PostWriter $escritor): void
    {
        $ideias = Post::where('status', Post::IDEA)->whereNotNull('change_request')->oldest('updated_at')->limit(2)->get();
        foreach ($ideias as $ideia) {
            // Se falhar, só tenta outra vez daqui a 15 minutos (não gasta o limite da IA a cada minuto).
            if (! Cache::add("calendario-ia:troca:{$ideia->id}", 1, now()->addMinutes(15))) {
                continue;
            }
            $quando = $ideia->scheduled_at ?? now()->addDays(7)->setTime(10, 0);
            try {
                $nova = $escritor->proporIdeias($quando, $quando, 1, DatasEspeciais::entre($quando, $quando),
                    $this->recentes(), [], 'Tema atual: “'.$ideia->title.'”. Pedido: '.$ideia->change_request)[0] ?? null;
                if (! $nova) {
                    throw new \RuntimeException('A IA não propôs nenhum tema.');
                }
            } catch (Throwable $e) {
                Log::warning("Trocar tema da ideia #{$ideia->id} falhou: ".$e->getMessage());
                $this->warn("Ideia #{$ideia->id}: ".$e->getMessage());

                continue;
            }

            $pedido = $ideia->change_request;
            $ideia->update([
                'title' => $nova['titulo'],
                'source' => 'Tipo: '.$nova['tipo'].'. '.$nova['notas'],
                'change_request' => null,
            ]);
            $ideia->reviews()->create(['action' => 'auto_swapped', 'comment' => 'pedido: '.$pedido]);
            $this->info("Ideia #{$ideia->id} com tema novo: {$nova['titulo']}");
        }
    }

    /** 2. Ideias perto da data: passam a pedido para a IA (como o botão "A IA faz este post"). */
    private function fazerIdeias(): void
    {
        $dias = (int) config('ia.ideias_dias_antes');
        if ($dias <= 0) {
            return;
        }

        $ideias = Post::where('status', Post::IDEA)
            ->whereNull('change_request') // à espera de outro tema: primeiro troca-se
            ->where('scheduled_at', '>', now())
            ->where('scheduled_at', '<=', now()->addDays($dias))
            // nem as que já têm pedido, nem as que já falharam (essas ficam para o botão, para não repetir o erro sem fim)
            ->whereNotIn('id', PostRequest::whereNotNull('post_id')->whereIn('status', ['new', 'in_progress', 'failed'])->select('post_id'))
            ->orderBy('scheduled_at')->get();

        foreach ($ideias as $ideia) {
            PostRequest::paraIdeia($ideia);
            $ideia->decide('auto_requested', Post::CREATING, null,
                'faltam '.(int) ceil(now()->diffInHours($ideia->scheduled_at) / 24).' dias');
            $this->info("A IA vai fazer a ideia #{$ideia->id}: {$ideia->title}");
        }
    }

    /** 3. Ideias da semana seguinte, até ao número de posts por semana. */
    private function proporSemana(PostWriter $escritor, bool $agora): void
    {
        $porSemana = (int) config('ia.ideias_por_semana');
        $inicio = now()->startOfWeek()->addWeek();   // segunda-feira da próxima semana
        $fim = $inicio->copy()->endOfWeek();
        $feito = "calendario-ia:semana:{$inicio->format('Y-m-d')}";

        if ($porSemana <= 0) {
            return;
        }
        if (! $agora) {
            // Automático: de segunda a sexta a partir das 8h, uma vez por semana; se falhar, tenta de 30 em 30 minutos.
            if (now()->isWeekend() || now()->hour < 8 || Cache::has($feito)
                || ! Cache::add("calendario-ia:tentativa:{$inicio->format('Y-m-d')}", 1, now()->addMinutes(30))) {
                return;
            }
        }

        $daSemana = Post::where('status', '!=', Post::REJECTED)->whereBetween('scheduled_at', [$inicio, $fim])->get();
        $faltam = $porSemana - $daSemana->count();
        if ($faltam <= 0) {
            Cache::put($feito, 1, now()->addDays(14));
            $this->line('A próxima semana já tem posts suficientes.');

            return;
        }

        try {
            $ideias = $escritor->proporIdeias($inicio, $fim, $faltam, DatasEspeciais::entre($inicio, $fim), $this->recentes(),
                $daSemana->map(fn ($p) => $p->scheduled_at->format('Y-m-d'))->unique()->values()->all());
        } catch (Throwable $e) {
            Log::warning('Propor ideias da semana falhou: '.$e->getMessage());
            $this->warn($e->getMessage());

            return;
        }

        foreach ($ideias as $i) {
            $post = Post::create([
                'title' => $i['titulo'],
                'networks' => ['Instagram', 'Facebook'],
                'scheduled_at' => $i['quando'],
                'source' => 'Tipo: '.$i['tipo'].'. '.$i['notas'],
                'status' => Post::IDEA,
            ]);
            $post->reviews()->create(['action' => 'auto_idea']);
            $this->info("Ideia #{$post->id} ({$post->whenLabel()}): {$post->title}");
        }
        Cache::put($feito, 1, now()->addDays(14));
    }

    /** Temas dos últimos 2 meses e dos já marcados (incluindo os rejeitados e descartados), para não repetir. */
    private function recentes(): array
    {
        return Post::where(fn ($q) => $q->where('scheduled_at', '>=', now()->subDays(60))->orWhere('created_at', '>=', now()->subDays(60)))
            ->latest('id')->limit(60)->pluck('title')->all();
    }
}
