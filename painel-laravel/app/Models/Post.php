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

    public const NETWORKS = ['Instagram', 'Facebook', 'LinkedIn'];

    protected $fillable = [
        'title', 'caption', 'image_path', 'image_alt', 'networks',
        'scheduled_at', 'status', 'source', 'change_request',
    ];

    protected function casts(): array
    {
        return [
            'networks' => 'array',
            'scheduled_at' => 'datetime',
            'decided_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** Máximo de tentativas de publicação antes de desistir (o erro fica no painel). */
    public const MAX_PUBLISH_ATTEMPTS = 3;

    /** Redes que a publicação automática já cobre. O LinkedIn fica para depois. */
    public const AUTO_NETWORKS = ['Facebook', 'Instagram'];

    public function reviews(): HasMany
    {
        return $this->hasMany(PostReview::class)->latest('id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
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
    public function decide(string $action, string $newStatus, ?User $user, ?string $comment = null): void
    {
        $this->status = $newStatus;
        $this->decided_by = $user?->id;
        $this->decided_at = now();
        if (in_array($action, ['change_requested', 'idea_swap'], true)) {
            $this->change_request = $comment;
        }
        $this->save();

        $this->reviews()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'comment' => $comment,
        ]);
    }
}
