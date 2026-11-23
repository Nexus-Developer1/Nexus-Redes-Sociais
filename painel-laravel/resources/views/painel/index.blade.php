@extends('layout')

@section('title', $sections[$ver]['title'])

@section('body')

<div class="grelha">
    <section class="coluna-principal">
        <div>
            <h1>{{ $sections[$ver]['title'] }}</h1>
            @if ($ver === 'fila')
                <p class="sub">Posts feitos pela IA, pelo Claude ou pela equipa. Nada é publicado sem a sua aprovação.</p>
            @elseif ($ver === 'ideias')
                @php($diasAntesIa = app(\App\Services\PostWriter::class)->configurado() ? (int) config('ia.ideias_dias_antes') : 0)
                <p class="sub">Temas do calendário. Cada ideia vira um post que chega à fila para aprovar{{ $diasAntesIa > 0 ? ' (a IA faz cada uma '.$diasAntesIa.' dias antes da data)' : '' }}. Pode trocar o tema ou descartar.</p>
            @endif
        </div>

        @if ($resumo)
            {{-- Resumo: o que espera por si, o que sai a seguir e o que correu mal --}}
            <div class="resumo" aria-label="Resumo">
                <div class="resumo-item {{ $resumo['para_aprovar'] ? 'resumo-item--destaque' : '' }}">
                    <span class="resumo-num">{{ $resumo['para_aprovar'] }}</span>
                    <span class="resumo-txt">para aprovar
                        @if ($resumo['atrasados'])<b class="resumo-alerta">{{ $resumo['atrasados'] }} {{ $resumo['atrasados'] === 1 ? 'atrasado' : 'atrasados' }}</b>@endif
                    </span>
                </div>
                <a class="resumo-item" href="{{ route('painel', ['ver' => 'agendados']) }}">
                    <span class="resumo-num">{{ $resumo['agendados'] }}</span>
                    <span class="resumo-txt">{{ $resumo['agendados'] === 1 ? 'agendado' : 'agendados' }}</span>
                </a>
                <div class="resumo-item">
                    <span class="resumo-num resumo-num--texto">{{ $resumo['proximo'] ? $resumo['proximo']->whenLabel() : '—' }}</span>
                    <span class="resumo-txt">{{ $resumo['proximo'] ? 'próxima publicação: '.$resumo['proximo']->title : 'nada aprovado para sair' }}</span>
                </div>
                <a class="resumo-item" href="{{ route('painel', ['ver' => 'ideias']) }}">
                    <span class="resumo-num">{{ $resumo['ideias'] }}</span>
                    <span class="resumo-txt">{{ $resumo['ideias'] === 1 ? 'ideia no calendário' : 'ideias no calendário' }}</span>
                </a>
            </div>
            @if (! $resumo['meta_ligada'])
                <div class="aviso aviso-info" role="note">
                    <strong>A publicação automática ainda não está ligada.</strong>
                    Pode aprovar à vontade: os posts aprovados ficam agendados e saem sozinhos quando a ligação à Meta (Instagram e Facebook) estiver feita.
                </div>
            @endif
            @if ($resumo['falhas'])
                <a class="aviso erro aviso-link" href="{{ route('painel', ['ver' => 'agendados']) }}">
                    {{ $resumo['falhas'] === 1 ? '1 post aprovado não foi publicado' : $resumo['falhas'].' posts aprovados não foram publicados' }}. Ver em “Agendados” ›
                </a>
            @endif
        @endif

        @if (session('erro'))
            <div class="aviso erro" role="alert">{{ session('erro') }}</div>
        @endif
        @if ($pronto && $pronto->status === \App\Models\Post::PENDING)
            <div class="aviso pronto" role="status">✓ “{{ $pronto->title }}” está pronto para aprovar.</div>
        @endif
        @if (session('ok'))
            <div class="aviso" role="status"
                 @if (session('pedido_em_curso')) data-espera="pedido" data-id="{{ session('pedido_em_curso') }}" @endif>{{ session('ok') }}</div>
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
                    <div class="vazio__icone" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                    </div>
                    @if ($ver === 'fila')
                        <h2>Está tudo aprovado</h2>
                        <p>Não há posts à espera. Os próximos chegam sozinhos do calendário, ou pode pedir um já.</p>
                        <div class="vazio-acoes">
                            <a class="btn btn-principal" href="#topic">Pedir um post</a>
                            <a class="btn" href="{{ route('calendario') }}">Ver o calendário</a>
                        </div>
                    @else
                        <h2>Nada para mostrar aqui</h2>
                        <p><a href="{{ route('painel') }}">Voltar à fila de aprovação</a></p>
                    @endif
                </div>
            @else
                <div class="lista-posts">
                    @foreach ($posts as $post)
                        @include('painel.post')
                    @endforeach
                </div>
                @if ($posts instanceof \Illuminate\Contracts\Pagination\Paginator && ($posts->previousPageUrl() || $posts->nextPageUrl()))
                    <nav class="paginas" aria-label="Páginas">
                        @if ($posts->previousPageUrl())<a class="btn" href="{{ $posts->previousPageUrl() }}">‹ Mais recentes</a>@endif
                        <span>Página {{ $posts->currentPage() }}</span>
                        @if ($posts->nextPageUrl())<a class="btn" href="{{ $posts->nextPageUrl() }}">Mais antigos ›</a>@endif
                    </nav>
                @endif
            @endif
        @endif
    </section>

    <aside class="coluna-lateral">
        <section class="bloco">
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
            <fieldset class="formato-pedido">
                <legend>Formato</legend>
                <label><input type="radio" name="formato" value="imagem" @checked(old('formato', 'imagem') === 'imagem')> Imagem única</label>
                <label><input type="radio" name="formato" value="carrossel" @checked(old('formato') === 'carrossel')> Carrossel
                    <small>(capa + um slide por ponto)</small></label>
            </fieldset>
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
