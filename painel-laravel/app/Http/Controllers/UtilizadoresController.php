<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * "Utilizadores" (só administradores): adicionar colegas, repor palavras-passe,
 * escolher quem é administrador e remover contas. O histórico dos posts mantém-se.
 */
class UtilizadoresController extends Controller
{
    public function index(Request $request): View
    {
        $this->soAdmin($request);

        return view('painel.utilizadores', ['users' => User::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->soAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'is_admin' => ['nullable', 'boolean'],
        ], ['email.unique' => 'Já existe uma conta com esse email.']);

        $password = $this->novaPassword();
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $password]);
        $user->forceFill(['is_admin' => (bool) ($data['is_admin'] ?? false)])->save();

        return back()->with('password', [$user->email, $password])
            ->with('ok', "Conta criada para {$user->name}.");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->soAdmin($request);
        $password = $this->novaPassword();
        $user->update(['password' => $password]);

        return back()->with('password', [$user->email, $password])
            ->with('ok', "Palavra-passe nova para {$user->name}.");
    }

    public function toggleAdmin(Request $request, User $user): RedirectResponse
    {
        $this->soAdmin($request);
        if ($user->is_admin && $this->ultimoAdmin($user)) {
            return back()->with('erro', 'Tem de ficar pelo menos um administrador.');
        }
        $user->forceFill(['is_admin' => ! $user->is_admin])->save();

        return back()->with('ok', $user->is_admin ? "{$user->name} passou a administrador." : "{$user->name} deixou de ser administrador.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->soAdmin($request);
        if ($user->is($request->user())) {
            return back()->with('erro', 'Não pode remover a sua própria conta.');
        }
        if ($user->is_admin && $this->ultimoAdmin($user)) {
            return back()->with('erro', 'Tem de ficar pelo menos um administrador.');
        }
        $user->delete(); // o histórico dos posts fica, sem o nome

        return back()->with('ok', "A conta de {$user->name} foi removida.");
    }

    private function soAdmin(Request $request): void
    {
        abort_unless($request->user()->is_admin, 403, 'Só os administradores podem gerir utilizadores.');
    }

    private function ultimoAdmin(User $user): bool
    {
        return User::where('is_admin', true)->whereKeyNot($user->id)->doesntExist();
    }

    /** Palavra-passe temporária, fácil de ditar (sem 0/O, 1/l). */
    private function novaPassword(): string
    {
        $letras = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        return collect(range(1, 12))->map(fn () => $letras[random_int(0, strlen($letras) - 1)])->implode('');
    }
}
