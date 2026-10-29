@extends('layout')

@section('title', 'Utilizadores')

@section('body')
@include('painel.topo')

<div class="pagina-estreita">
    <a href="{{ route('painel') }}" class="voltar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg> Voltar ao painel</a>
    <h1>Utilizadores</h1>
    <p class="sub">Quem pode entrar no painel. Todos podem pedir, criar, editar e aprovar posts; os administradores gerem também esta página.</p>

    @if (session('ok'))<div class="aviso" role="status">{{ session('ok') }}</div>@endif
    @if (session('erro'))<div class="aviso erro" role="alert">{{ session('erro') }}</div>@endif
    @if ($errors->any())
        <div class="aviso erro" role="alert">@foreach ($errors->all() as $e){{ $e }}<br>@endforeach</div>
    @endif

    {{-- A palavra-passe só aparece uma vez: a pessoa muda-a depois em "A minha conta" --}}
    @if (session('password'))
        @php([$emailNovo, $passNova] = session('password'))
        <div class="caixa password-nova" role="status">
            <div><strong>Envie estes dados à pessoa</strong> (só aparecem agora):</div>
            <div class="credenciais">
                <span>Endereço: <code>{{ url('/') }}</code></span>
                <span>Email: <code>{{ $emailNovo }}</code></span>
                <span>Palavra-passe: <code>{{ $passNova }}</code></span>
            </div>
            <small>Depois de entrar, a pessoa pode mudá-la em “A minha conta”.</small>
        </div>
    @endif

    <div class="caixa lista-users">
        @foreach ($users as $u)
            <div class="user-linha">
                <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($u->name, 0, 1)) }}</span>
                <div class="user-info">
                    <b>{{ $u->name }}</b> @if ($u->is(auth()->user()))<small>(você)</small>@endif
                    <div class="origem">{{ $u->email }}</div>
                </div>
                @if ($u->is_admin)<span class="estado estado-approved">Administrador</span>@endif
                <div class="user-acoes">
                    <form method="POST" action="{{ route('users.reset', $u) }}"
                          onsubmit="return confirm('Criar uma palavra-passe nova para {{ $u->name }}? A atual deixa de funcionar.')">
                        @csrf<button type="submit" class="btn pequeno">Nova palavra-passe</button>
                    </form>
                    <form method="POST" action="{{ route('users.admin', $u) }}">
                        @csrf<button type="submit" class="btn pequeno">{{ $u->is_admin ? 'Tirar admin' : 'Tornar admin' }}</button>
                    </form>
                    @unless ($u->is(auth()->user()))
                        <form method="POST" action="{{ route('users.destroy', $u) }}"
                              onsubmit="return confirm('Remover a conta de {{ $u->name }}? O histórico dos posts mantém-se.')">
                            @csrf<button type="submit" class="btn pequeno btn-perigo">Remover</button>
                        </form>
                    @endunless
                </div>
            </div>
        @endforeach
    </div>

    <form method="POST" action="{{ route('users.store') }}" class="caixa formulario">
        @csrf
        <h2>Adicionar pessoa</h2>
        <div class="dupla">
            <label>Nome <input name="name" required maxlength="100" value="{{ old('name') }}" placeholder="Ex.: Ana Costa"></label>
            <label>Email <input type="email" name="email" required maxlength="150" value="{{ old('email') }}" placeholder="ana@nexus-solutions.pt"></label>
        </div>
        <label class="check"><input type="checkbox" name="is_admin" value="1"> Administrador (pode gerir utilizadores)</label>
        <small>É criada uma palavra-passe temporária, que aparece aqui para enviar à pessoa.</small>
        <button type="submit" class="btn btn-principal">Adicionar</button>
    </form>
</div>
@endsection
