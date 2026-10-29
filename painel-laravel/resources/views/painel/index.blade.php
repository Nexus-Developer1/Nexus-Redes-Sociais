@extends('layout')

@section('title', $sections[$ver]['title'])

@section('body')
@include('painel.topo')

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
        <a href="{{ route('calendario') }}" class="menu-calendario">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
            Calendário
        </a>
        @if (auth()->user()->is_admin)
            <a href="{{ route('users.index') }}" class="menu-calendario menu-extra">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6"/></svg>
                Utilizadores
            </a>
        @endif
    </nav>

    <main class="principal">
        <div>
            <h1>{{ $sections[$ver]['title'] }}</h1>
            @if ($ver === 'fila')
                <p class="sub">Posts feitos pela IA, pelo Claude ou pela equipa. Nada é publicado sem a sua aprovação.</p>
            @elseif ($ver === 'ideias')
                <p class="sub">Calendário de temas (1 post por semana). Cada ideia vira um post que chega à fila para aprovar. Pode trocar o tema ou descartar.</p>
            @endif
        </div>

        @if (session('erro'))
            <div class="aviso erro" role="alert">{{ session('erro') }}</div>
        @endif
        @if (session('ok'))
            <div class="aviso" role="status">{{ session('ok') }}</div>
        @endif
        @if ($errors->has('scheduled_at'))
            <div class="aviso erro" role="alert">{{ $errors->first('scheduled_at') }}</div>
        @endif
        @if ($errors->has('comment'))
            <div class="aviso erro" role="alert">{{ $errors->first('comment') }}</div>
        @endif

        @if ($ver === 'pedidos')
            @include('painel.pedidos')
        @else
            @if ($posts->isEmpty())
                <div class="vazio">
                    {{ $ver === 'fila' ? 'Não há posts à espera de aprovação.' : 'Nada para mostrar aqui.' }}
                </div>
            @else
                <div class="lista-posts">
                    @foreach ($posts as $post)
                        @include('painel.post')
                    @endforeach
                </div>
            @endif
        @endif
    </main>

    <aside class="lateral">
        <a class="criar-post" href="{{ route('posts.create') }}">+ Criar post</a>

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
            <label for="topic">Escreva o tema: a IA prepara o post e põe-no em “Para aprovar”.</label>
            <textarea id="topic" name="topic" rows="3" required maxlength="2000"
                      placeholder="Ex.: aviso de fecho no feriado de 1 de dezembro">{{ old('topic') }}</textarea>
            @error('topic')<div class="erro">{{ $message }}</div>@enderror
            <label for="publish_at">Publicar em <small>(opcional; vazio = a IA escolhe)</small></label>
            <input type="datetime-local" id="publish_at" name="publish_at" value="{{ old('publish_at') }}"
                   min="{{ now()->format('Y-m-d\TH:i') }}">
            @error('publish_at')<div class="erro">{{ $message }}</div>@enderror
            <button type="submit">Pedir post</button>
        </form>
    </aside>
</div>

<script>
// Legendas longas aparecem resumidas; "Ver legenda completa" abre e fecha.
document.querySelectorAll('[data-legenda]').forEach(function (el) {
    var botao = el.nextElementSibling;
    if (!botao || el.scrollHeight <= el.clientHeight + 4) return;
    el.classList.add('cortada');
    botao.hidden = false;
    botao.addEventListener('click', function () {
        var aberta = el.classList.toggle('aberta');
        botao.textContent = aberta ? 'Ver menos' : 'Ver legenda completa';
    });
});
</script>
@endsection
