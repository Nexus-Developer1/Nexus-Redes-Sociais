<?php

namespace App\Http\Controllers;

use App\Models\PostRequest;
use App\Services\PostWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PostRequestController extends Controller
{
    public function store(Request $request, PostWriter $escritor): RedirectResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:2000'],
            'publish_at' => ['nullable', 'date', 'after:now'],
        ], [
            'topic.required' => 'Escreva o tema do post.',
            'publish_at.after' => 'A data de publicação tem de ser no futuro.',
        ]);

        PostRequest::create([
            'topic' => $data['topic'],
            'publish_at' => $data['publish_at'] ?? null,
            'user_id' => $request->user()->id,
        ]);

        return back()->with('ok', $escritor->configurado()
            ? 'Pedido registado. A IA está a preparar o post: aparece em “Para aprovar” dentro de 1 a 2 minutos.'
            : 'Pedido registado. Fica na lista “Pedidos de posts” até ser tratado (a IA automática ainda não está ligada).');
    }

    /** Volta a pôr na fila um pedido em que a IA falhou. */
    public function retry(PostRequest $pedido): RedirectResponse
    {
        abort_unless($pedido->status === 'failed', 409, 'Este pedido não falhou.');
        $pedido->update(['status' => 'new', 'error' => null]);

        return back()->with('ok', 'O pedido voltou à fila; a IA tenta outra vez dentro de um minuto.');
    }
}
