<?php

namespace App\Http\Controllers;

use App\Models\Post;

abstract class Controller
{
    /** Só se mexe num post antes de aprovado (para o alterar depois, desfaz-se a aprovação). */
    protected function mustBeEditable(Post $post): void
    {
        abort_unless($post->isEditable(), 409,
            'Este post já foi aprovado ou publicado. Desfaça a aprovação para o alterar.');
    }
}
