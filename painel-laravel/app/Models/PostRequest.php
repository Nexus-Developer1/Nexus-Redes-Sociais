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

    protected $fillable = ['topic', 'status', 'user_id', 'post_id', 'error'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
