<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** "A minha conta": cada pessoa muda o nome, o email e a palavra-passe. */
class ContaController extends Controller
{
    public function show(): View
    {
        return view('painel.conta');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users')->ignore($user->id)],
        ], ['email.unique' => 'Já existe outra conta com esse email.']);

        $user->update($data);

        return back()->with('ok', 'Dados guardados.');
    }

    public function password(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ], [
            'current_password.current_password' => 'A palavra-passe atual não está certa.',
            'password.min' => 'A palavra-passe nova tem de ter pelo menos 10 caracteres.',
            'password.confirmed' => 'As duas palavras-passe novas não são iguais.',
        ]);

        $request->user()->update(['password' => $request->input('password')]);
        $request->session()->regenerate();

        return back()->with('ok', 'Palavra-passe mudada.');
    }
}
