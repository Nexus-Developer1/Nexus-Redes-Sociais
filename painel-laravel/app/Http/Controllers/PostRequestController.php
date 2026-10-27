<?php

namespace App\Http\Controllers;

use App\Models\PostRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PostRequestController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:2000'],
        ], [
            'topic.required' => 'Escreva o tema do post.',
        ]);

        PostRequest::create([
            'topic' => $data['topic'],
            'user_id' => $request->user()->id,
        ]);

        return back()->with('ok', 'Pedido registado. O Claude vai preparar o rascunho.');
    }
}
