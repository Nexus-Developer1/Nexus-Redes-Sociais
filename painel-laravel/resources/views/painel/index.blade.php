@extends('layout')

@section('title', $sections[$ver]['title'])

@section('body')
<header class="topo">
    <a class="marca" href="{{ route('painel') }}"><b>NEXUS</b><span>Redes sociais</span></a>
    <div class="utilizador">
        <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
        <span>{{ auth()->user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sair</button></form>
    </div>
</header>

<div class="grelha">
    <nav class="menu" aria-label="Secções">
        @foreach ($sections as $key => $s)
            <a href="{{ route('painel', $key === 'fila' ? [] : ['ver' => $key]) }}"
               class="{{ $ver === $key ? 'ativo' : '' }}"
               @if ($ver === $key) aria-current="page" @endif>
                {{ $s['title'] }}
                @if ($s['count'] > 0)<span class="num">{{ $s['count'] }}</span>@endif
            </a>
        @endforeach
    </nav>

    <main class="principal">
        <div>
            <h1>{{ $sections[$ver]['title'] }}</h1>
            @if ($ver === 'fila')
                <p class="sub">Posts criados pelo Claude. Nada é publicado sem a sua aprovação.</p>
            @endif
        </div>

        @if (session('ok'))
            <div class="aviso" role="status">{{ session('ok') }}</div>
        @endif
        @if ($errors->has('comment'))
            <div class="aviso erro" role="alert">{{ $errors->first('comment') }}</div>
        @endif

        @if ($ver === 'pedidos')
            @include('painel.pedidos')
        @else
            @forelse ($posts as $post)
                @include('painel.post')
            @empty
                <div class="vazio">
                    {{ $ver === 'fila' ? 'Não há posts à espera de aprovação.' : 'Nada para mostrar aqui.' }}
                </div>
            @endforelse
        @endif
    </main>

    <aside class="lateral">
        <section class="caixa">
            <h2>Próximos posts</h2>
            @if ($upcoming->isEmpty())
                <p class="origem">Ainda não há posts planeados.</p>
            @else
                <div class="proximos">
                    @foreach ($upcoming as $u)
                        @php($d = $u->scheduled_at->locale('pt_PT'))
                        <div class="proximo">
                            <div class="dia"><small>{{ rtrim($d->translatedFormat('D'), '.') }}</small><b>{{ $d->format('j') }}</b></div>
                            <div>
                                <div class="t">{{ $u->title }}</div>
                                <div class="s">{{ $u->statusLabel() }} · {{ implode(', ', $u->networks) }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <form class="pedir" method="POST" action="{{ route('requests.store') }}">
            @csrf
            <h2>Pedir um post</h2>
            <label for="topic">Escreva o tema e o Claude prepara o rascunho.</label>
            <textarea id="topic" name="topic" rows="3" required maxlength="2000"
                      placeholder="Ex.: aviso de fecho no feriado de 1 de dezembro">{{ old('topic') }}</textarea>
            @error('topic')<div class="erro">{{ $message }}</div>@enderror
            <button type="submit">Criar rascunho</button>
        </form>
    </aside>
</div>
@endsection
