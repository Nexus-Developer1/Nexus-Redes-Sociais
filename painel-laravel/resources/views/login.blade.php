@extends('layout')

@section('title', 'Entrar')

@section('body')
<div class="login">
    <form method="POST" action="{{ url('/entrar') }}">
        @csrf
        <h1><img src="{{ asset('modelos/logo-nexus.png') }}" alt="NEXUS" width="150" height="28"> Redes sociais</h1>
        <p class="sub" style="margin:0;color:#4f6157">Entre para aprovar os posts.</p>

        @error('email')
            <div class="aviso erro" role="alert">{{ $message }}</div>
        @enderror

        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">

        <label for="password">Palavra-passe</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">

        <label class="lembrar"><input type="checkbox" name="remember" value="1"> Manter sessão iniciada</label>

        <button type="submit" class="btn btn-principal">Entrar</button>
    </form>
</div>
@endsection
