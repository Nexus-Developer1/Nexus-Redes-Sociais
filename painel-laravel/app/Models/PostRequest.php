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
    ];

    protected $fillable = ['topic', 'status', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
