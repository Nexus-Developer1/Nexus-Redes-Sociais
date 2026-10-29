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
        'submitted' => 'enviou para aprovação',
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
