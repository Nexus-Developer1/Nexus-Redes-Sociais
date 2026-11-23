<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    public const IDEA = 'idea';          // ideia proposta
    public const CREATING = 'creating';  // o Claude está a criar
    public const PENDING = 'pending';    // à espera de aprovação
    public const REVISING = 'revising';  // o Claude está a refazer
    public const APPROVED = 'approved';  // aprovado e agendado
    public const REJECTED = 'rejected';
    public const PUBLISHED = 'published';

    public const LABELS = [
        self::IDEA => 'Ideia proposta',
        self::CREATING => 'Em criação',
        self::PENDING => 'À espera de aprovação',
        self::REVISING => 'Claude a refazer',
        self::APPROVED => 'Aprovado e agendado',
        self::REJECTED => 'Rejeitado',
        self::PUBLISHED => 'Publicado',
    ];

    /** "Stories" = Stories do Instagram, com a versão 9:16 do post que o servidor gera sozinho. */
    public const NETWORKS = ['Instagram', 'Stories', 'Facebook', 'LinkedIn'];

    protected $fillable = [
        'title', 'caption', 'image_path', 'imagens_carrossel', 'imagem_story', 'image_alt', 'design', 'networks',
        'scheduled_at', 'status', 'source', 'change_request',
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

    /** Quantos posts há em cada estado. Contado uma só vez por pedido (barra lateral, resumo da fila…). */
    public static function contagens(): \Illuminate\Support\Collection
    {
        return once(fn () => static::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'));
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
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
