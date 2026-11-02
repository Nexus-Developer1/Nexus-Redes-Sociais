<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostRequest extends Model
{
    public const LABELS = [
        'new' => 'Novo',
        'in_progress' => 'Em criação',
        'done' => 'Feito',
        'failed' => 'Falhou',
    ];

    protected $fillable = ['topic', 'publish_at', 'status', 'user_id', 'post_id', 'error'];

    protected function casts(): array
    {
        return ['publish_at' => 'datetime'];
    }

    /** Pedido para a IA fazer o post de uma ideia do calendário (tema + notas + data da ideia). */
    public static function paraIdeia(Post $ideia, ?int $userId = null): self
    {
        $notas = $ideia->change_request ?: $ideia->source;

        return self::create([
            'topic' => mb_substr($ideia->title.($notas ? '. Notas: '.$notas : ''), 0, 2000),
            'publish_at' => $ideia->scheduled_at?->isFuture() ? $ideia->scheduled_at : null,
            'user_id' => $userId,
            'post_id' => $ideia->id,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
