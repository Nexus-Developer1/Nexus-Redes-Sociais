@extends('layout')

@section('title', 'A minha conta')

@section('body')
@include('painel.topo')

<div class="pagina-estreita">
    <a href="{{ route('painel') }}" class="voltar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg> Voltar ao painel</a>
    <h1>A minha conta</h1>

    @if (session('ok'))<div class="aviso" role="status">{{ session('ok') }}</div>@endif
    @if ($errors->any())
        <div class="aviso erro" role="alert">@foreach ($errors->all() as $e){{ $e }}<br>@endforeach</div>
    @endif

    <form method="POST" action="{{ route('conta.update') }}" class="caixa formulario">
        @csrf
        <h2>Dados</h2>
        <label>Nome <input name="name" required maxlength="100" value="{{ old('name', auth()->user()->name) }}"></label>
        <label>Email <small>(é o que usa para entrar)</small>
            <input type="email" name="email" required maxlength="150" value="{{ old('email', auth()->user()->email) }}">
        </label>
        <button type="submit" class="btn btn-principal">Guardar</button>
    </form>

    <form method="POST" action="{{ route('conta.password') }}" class="caixa formulario">
        @csrf
        <h2>Mudar a palavra-passe</h2>
        <label>Palavra-passe atual <input type="password" name="current_password" required autocomplete="current-password"></label>
        <label>Palavra-passe nova <small>(pelo menos 10 caracteres)</small>
            <input type="password" name="password" required minlength="10" autocomplete="new-password">
        </label>
        <label>Repita a palavra-passe nova <input type="password" name="password_confirmation" required minlength="10" autocomplete="new-password"></label>
        <button type="submit" class="btn btn-principal">Mudar palavra-passe</button>
    </form>

    @if (auth()->user()->is_admin)
        <p class="origem">É administrador: pode gerir as contas dos colegas em <a href="{{ route('users.index') }}">Utilizadores</a>.</p>
    @endif
</div>
@endsection
