<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostReview extends Model
{
    public const UPDATED_AT = null;

    public const LABELS = [
        'approved' => 'aprovou',
        'rejected' => 'rejeitou',
        'change_requested' => 'pediu alteração',
        'undone' => 'desfez a decisão',
        'idea_swap' => 'pediu outro tema',
        'discarded' => 'descartou a ideia',
        'created' => 'criou o post no painel',
        'edited' => 'editou o post',
        'rescheduled' => 'mudou a data',
        'photo_changed' => 'trocou a foto',
        'submitted' => 'enviou para aprovação',
        'ai_requested' => 'pediu à IA para fazer o post',
        'auto_requested' => 'mandou a IA fazer o post (automático, uns dias antes da data)',
        'auto_idea' => 'propôs esta ideia com a IA (calendário automático)',
        'auto_swapped' => 'trocou o tema com a IA',
        'auto_created' => 'criou o post com a IA, a partir de um pedido',
        'auto_revised' => 'refez o post com a IA, com a alteração pedida',
        'published' => 'publicou',
        'publish_failed' => 'falhou a publicação',
    ];

    protected $fillable = ['user_id', 'action', 'comment'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
