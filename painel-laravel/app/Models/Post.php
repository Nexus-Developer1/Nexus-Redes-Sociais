<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    public const IDEA = 'idea';          // ideia proposta
    public const CREATING = 'creating';  // em criação (no editor ou pela IA)
    public const PENDING = 'pending';    // à espera de aprovação
    public const REVISING = 'revising';  // alteração pedida, à espera da IA (ou de quem o editar)
    public const APPROVED = 'approved';  // aprovado e agendado
    public const REJECTED = 'rejected';
    public const PUBLISHED = 'published';

    public const LABELS = [
        self::IDEA => 'Ideia proposta',
        self::CREATING => 'Em criação',
        self::PENDING => 'À espera de aprovação',
        self::REVISING => 'Alteração pedida',
        self::APPROVED => 'Aprovado e agendado',
        self::REJECTED => 'Rejeitado',
        self::PUBLISHED => 'Publicado',
    ];

    /** "Stories" = Stories do Instagram, com a versão 9:16 do post que o servidor gera sozinho. */
    public const NETWORKS = ['Instagram', 'Stories', 'Facebook', 'LinkedIn'];

    /**
     * Cada post tem sempre as duas vertentes: as redes sociais (estas três, sempre) e o email aos parceiros
     * (enviar_parceiros, ligado por defeito; só se desliga num caso excecional).
     */
    public const REDES_SEMPRE = ['Instagram', 'Facebook', 'LinkedIn'];

    protected $attributes = ['enviar_parceiros' => true];

    protected $fillable = [
        'title', 'caption', 'image_path', 'imagens_carrossel', 'imagem_story', 'image_alt', 'design', 'networks',
        'scheduled_at', 'status', 'source', 'change_request', 'enviar_parceiros',
    ];

    protected function casts(): array
    {
        return [
            'networks' => 'array',
            'design' => 'array',
            'imagens_carrossel' => 'array',
            'scheduled_at' => 'datetime',
            'decided_at' => 'datetime',
            'published_at' => 'datetime',
            'enviar_parceiros' => 'boolean',
            'parceiros_enviado_em' => 'datetime',
        ];
    }

    /** Máximo de tentativas de publicação antes de desistir (o erro fica no painel). */
    public const MAX_PUBLISH_ATTEMPTS = 3;

    /** Redes que a publicação automática já cobre. O LinkedIn fica para depois. */
    public const AUTO_NETWORKS = ['Facebook', 'Instagram', 'Stories'];

    /** Imagens a publicar, por ordem: as do carrossel ou só a imagem do post. */
    public function imagens(): array
    {
        return $this->imagens_carrossel ?: array_values(array_filter([$this->image_path]));
    }

    /** Guarda o resultado do PostRenderer: a capa em image_path e, se for carrossel, todas as imagens. */
    public function guardarImagens(array $render): void
    {
        $slides = $render['slides'] ?? [];
        $this->update([
            'image_path' => $render['path'],
            'imagens_carrossel' => count($slides) > 1 ? array_values($slides) : null,
            'imagem_story' => $render['story'] ?? null,
        ]);
    }

    /** Redes pela ordem do painel; com $stories, os posts do Instagram vão também para os Stories (sem, saem). */
    public static function comStories(array $redes, bool $stories = true): array
    {
        $redes = array_diff($redes, ['Stories']);
        if ($stories && in_array('Instagram', $redes, true)) {
            $redes[] = 'Stories';
        }

        return array_values(array_intersect(self::NETWORKS, $redes));
    }

    /**
     * Fotos de fundo já usadas noutros posts (não rejeitados), para não repetir a mesma foto em posts diferentes.
     * 'banco' = ['pixabay-123', 'pexels-456'…], 'biblioteca' = [ids da biblioteca].
     *
     * @return array{banco: string[], biblioteca: int[]}
     */
    public static function fotosEmUso(?int $exceto = null): array
    {
        $emUso = ['banco' => [], 'biblioteca' => []];
        $designs = self::where('status', '!=', self::REJECTED)->whereNotNull('design')
            ->when($exceto, fn ($q) => $q->whereKeyNot($exceto))->pluck('design');
        foreach ($designs as $design) {
            foreach (['foto', 'fundo'] as $campo) {
                $nome = (string) ($design[$campo] ?? '');
                if (preg_match('/^((?:pixabay|pexels)-\d+)\.jpg$/', $nome, $m)) {
                    $emUso['banco'][] = $m[1];
                } elseif (preg_match('/^bib-(\d+)\./', $nome, $m)) {
                    $emUso['biblioteca'][] = (int) $m[1];
                }
            }
        }

        return array_map(fn ($l) => array_values(array_unique($l)), $emUso);
    }

    /** Aviso (fecho, feriado, horário)? Os avisos são extra: não contam para os posts da semana. */
    public function isAviso(): bool
    {
        return ($this->design['modelo'] ?? null) === 'aviso'
            || str_starts_with((string) $this->source, 'Tipo: aviso')
            || mb_stripos((string) $this->title, 'aviso') === 0;
    }

    /** Um aviso sai até 10 dias antes do feriado a que se refere (numa ponte, antes do fim de semana). */
    public const AVISO_DIAS_ANTES = 10;

    /** Feriado ('AAAA-MM-DD') de que um aviso fala: o primeiro feriado a partir do dia em que sai. Null se não houver. */
    public function feriadoDoAviso(): ?string
    {
        if (! $this->isAviso() || ! $this->scheduled_at) {
            return null;
        }
        $dia = $this->scheduled_at->copy()->startOfDay();
        foreach (\App\Services\DatasEspeciais::entre($dia, $dia->copy()->addDays(self::AVISO_DIAS_ANTES)) as $data => $nome) {
            if (str_starts_with($nome, 'Feriado')) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Outros avisos (não rejeitados) para o mesmo fecho: o mesmo feriado ou, num fecho sem feriado, o mesmo dia.
     * Ex.: "ponte de 30 de novembro" e "encerrados a 1 de dezembro" falam os dois do feriado de 1 de dezembro.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public function avisosRepetidos(): \Illuminate\Support\Collection
    {
        if (! $this->isAviso() || ! $this->scheduled_at) {
            return collect();
        }
        $feriado = $this->feriadoDoAviso();
        $dia = $this->scheduled_at->toDateString();

        return self::where('status', '!=', self::REJECTED)->whereKeyNot($this->id)
            ->whereBetween('scheduled_at', [
                $this->scheduled_at->copy()->subDays(self::AVISO_DIAS_ANTES)->startOfDay(),
                $this->scheduled_at->copy()->addDays(self::AVISO_DIAS_ANTES)->endOfDay(),
            ])
            ->orderBy('scheduled_at')->get()
            ->filter(fn (self $p) => $p->isAviso() && ($feriado ? $p->feriadoDoAviso() === $feriado : $p->scheduled_at->toDateString() === $dia))
            ->values();
    }

    /** Avisos (não rejeitados) que já falam deste feriado ('AAAA-MM-DD'), mesmo que saiam na semana anterior. */
    public static function avisosDoFeriado(string $feriado): \Illuminate\Support\Collection
    {
        $dia = \Illuminate\Support\Carbon::parse($feriado);

        return self::where('status', '!=', self::REJECTED)
            ->whereBetween('scheduled_at', [$dia->copy()->subDays(self::AVISO_DIAS_ANTES)->startOfDay(), $dia->copy()->endOfDay()])
            ->get()->filter(fn (self $p) => $p->isAviso() && $p->feriadoDoAviso() === $feriado)->values();
    }

    /** Vai também para os Stories do Instagram? */
    public function temStories(): bool
    {
        return in_array('Stories', $this->networks ?? [], true);
    }

    /** Imagem para os Stories: a versão 9:16 gerada, ou a do post (que já é 9:16 ou foi feita à parte). */
    public function imagemStory(): ?string
    {
        return $this->imagem_story ?: $this->image_path;
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PostReview::class)->latest('id');
    }

    /** Pedidos à IA feitos a partir deste post (ex.: "A IA faz este post"). */
    public function pedidos(): HasMany
    {
        return $this->hasMany(PostRequest::class);
    }

    /** Emails deste post aos parceiros (um por parceiro). */
    public function envios(): HasMany
    {
        return $this->hasMany(ParceiroEnvio::class);
    }

    /** Já saiu por email para algum parceiro (mesmo que o envio ainda não tenha acabado). */
    public function enviadoAParceiros(): bool
    {
        return $this->parceiros_enviado_em !== null || $this->envios()->whereNotNull('enviado_em')->exists();
    }

    /** Assunto do email aos parceiros: o título da imagem (sem asteriscos nem mudanças de linha) ou o nome do post. */
    public function assuntoParceiros(): string
    {
        $titulo = trim(preg_replace('/\s+/u', ' ', str_replace('*', '', (string) ($this->design['titulo'] ?? ''))));
        // No modelo "número" o número está à parte ("3" + "cuidados de TI…"): no título do email vai junto.
        if ($titulo !== '' && ($this->design['modelo'] ?? '') === 'numero' && filled($this->design['numero'] ?? null)) {
            $titulo = trim($this->design['numero']).' '.$titulo;
        }
        $titulo = $titulo !== '' ? $titulo : $this->title;

        return mb_substr(mb_strtoupper(mb_substr($titulo, 0, 1)).mb_substr($titulo, 1), 0, 150);
    }

    /** Legenda para o email aos parceiros: sem as linhas só de hashtags (no email não servem para nada). */
    public function legendaParaEmail(): string
    {
        $linhas = array_filter(preg_split('/\R/u', (string) $this->caption),
            fn ($l) => ! preg_match('/^\s*(#[\p{L}\p{N}_]+\s*)+$/u', $l));

        return trim(preg_replace('/
{3,}/', "

", implode("
", $linhas)));
    }

    /** Quantos posts há em cada estado. Contado uma só vez por pedido (barra lateral, resumo da fila…). */
    public static function contagens(): \Illuminate\Support\Collection
    {
        return once(fn () => static::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'));
    }

    /** Pode ser editado no painel? Aprovado ou publicado não (desfazer a aprovação primeiro). */
    public function isEditable(): bool
    {
        return ! in_array($this->status, [self::APPROVED, self::PUBLISHED], true);
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }

    /** Data em português, ex.: "seg, 26 out, 10:00". */
    public function whenLabel(): string
    {
        if (! $this->scheduled_at) {
            return 'Sem data';
        }

        $d = $this->scheduled_at->locale('pt_PT');

        if ($d->isToday()) {
            return 'Hoje, '.$d->format('H:i');
        }
        if ($d->isTomorrow()) {
            return 'Amanhã, '.$d->format('H:i');
        }

        return $d->translatedFormat('D, j M, H:i');
    }

    /**
     * Regista uma decisão de uma pessoa. É o único sítio onde o estado
     * muda por ação humana, para o histórico ficar sempre completo.
     */
    /**
     * Regista uma decisão. Com $de, só muda se o post ainda estiver num desses estados (numa só operação na base
     * de dados): se a IA ou outra pessoa o mudou entretanto, nada é gravado e devolve false.
     */
    public function decide(string $action, string $newStatus, ?User $user, ?string $comment = null, ?array $de = null): bool
    {
        $campos = ['status' => $newStatus, 'decided_by' => $user?->id, 'decided_at' => now()];
        if (in_array($action, ['change_requested', 'idea_swap'], true)) {
            $campos['change_request'] = $comment;
        }
        if ($de !== null) {
            $mudou = static::whereKey($this->id)->whereIn('status', $de)->update($campos + ['updated_at' => now()]);
            if ($mudou !== 1) {
                return false;
            }
            $this->forceFill($campos)->syncOriginal();
            $this->updated_at = now();
        } else {
            $this->forceFill($campos)->save();
        }

        $this->reviews()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'comment' => $comment,
        ]);

        return true;
    }

    /** Já saiu nalguma rede? (então não se pode desfazer: a rede tem a versão publicada) */
    public function publicadoAlgures(): bool
    {
        return (bool) ($this->facebook_post_id || $this->instagram_media_id || $this->instagram_story_id);
    }

    /** Estados em que um post pode ser eliminado (nunca aprovado nem publicado). */
    public const ELIMINAVEIS = [self::IDEA, self::CREATING, self::PENDING, self::REVISING, self::REJECTED];

    /**
     * Porque é que este post não pode ser eliminado (null = pode). Publicados ficam no histórico,
     * aprovados têm de ser desfeitos primeiro, e não se apaga um post em que a IA está a trabalhar.
     */
    public function motivoParaNaoEliminar(): ?string
    {
        if ($this->status === self::PUBLISHED || $this->publicadoAlgures()) {
            return 'Este post já saiu nas redes sociais: fica no histórico e não pode ser eliminado.';
        }
        if ($this->enviadoAParceiros()) {
            return 'Este post já foi enviado por email aos parceiros: fica no histórico e não pode ser eliminado.';
        }
        if (! in_array($this->status, self::ELIMINAVEIS, true)) {
            return 'Este post está aprovado e agendado. Use “Desfazer” primeiro e depois elimine-o.';
        }
        $iaAFazer = $this->ia_a_fazer ?? $this->pedidos()->whereIn('status', ['new', 'in_progress'])->exists();
        if ($this->aRefazer() || $iaAFazer) {
            return 'A IA está a trabalhar neste post. Espere que acabe (1 a 2 minutos) e tente outra vez.';
        }

        return null;
    }

    /** A IA está a refazer este post (pedido de alteração) e a gerar a imagem nova. */
    public function aRefazer(): bool
    {
        return $this->status === self::CREATING && $this->design && $this->change_request;
    }

    /** A hora marcada já passou (ou falta menos de uma hora)? Aprovar agora publica logo. */
    public function atrasado(): bool
    {
        return $this->scheduled_at !== null && $this->scheduled_at->lt(now()->addHour());
    }
}
