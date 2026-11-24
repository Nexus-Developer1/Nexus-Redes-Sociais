@extends('layout')

@section('title', $sections[$ver]['title'])

@section('body')
@php
    $diasAntesIa = app(\App\Services\PostWriter::class)->configurado() ? (int) config('ia.ideias_dias_antes') : 0;
    $subtitulos = [
        'fila' => 'Nada é publicado sem a sua aprovação.',
        'agendados' => 'Aprovados: saem sozinhos à hora marcada.',
        'publicados' => 'Já saíram nas redes sociais.',
        'rejeitados' => 'Posts e ideias que não vão ser publicados.',
        'ideias' => 'Temas do calendário e posts pedidos à IA'.($diasAntesIa > 0 ? ': a IA faz cada post '.$diasAntesIa.' dias antes da data' : '').'.',
    ];
@endphp

<div class="pagina">
    <x-cabecalho :titulo="$sections[$ver]['title']" :sub="$subtitulos[$ver] ?? null">
        <button type="button" class="btn btn-principal" data-abrir-dialogo="dlg-pedir">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            Pedir um post à IA
        </button>
    </x-cabecalho>

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
                <span class="resumo-txt">{{ $resumo['agendados'] === 1 ? 'agendado' : 'agendados' }}{{ $resumo['proximo'] ? ' · o próximo sai '.$resumo['proximo']->whenLabel() : '' }}</span>
            </a>
            <a class="resumo-item" href="{{ route('calendario') }}">
                <span class="resumo-num">{{ $resumo['ideias'] }}</span>
                <span class="resumo-txt">{{ $resumo['ideias'] === 1 ? 'ideia no calendário' : 'ideias no calendário' }}</span>
            </a>
        </div>
        @if (! $resumo['meta_ligada'])
            <p class="faixa-info" role="note">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                Publicação automática por ligar: o que aprovar fica agendado e sai quando a Meta estiver ligada.
            </p>
        @endif
        @unless (config('painel.emails_ligados'))
            <p class="faixa-info" role="note">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5l8.5 7 8.5-7"/></svg>
                Emails desligados por agora: não sai nenhum email (aprovação, lembretes ou parceiros). Aprove aqui no painel.
            </p>
        @endunless
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

    {{-- Em "Ideias e em criação": no topo, os pedidos à IA ainda sem post (a fazer agora ou que falharam) --}}
    @if ($requests->isNotEmpty())
        @include('painel.pedidos')
    @endif

    @if ($posts->isEmpty() && $requests->isNotEmpty())
        {{-- só os pedidos: sem "Nada para mostrar" por baixo --}}
    @elseif ($posts->isEmpty())
        <div class="vazio">
            <div class="vazio__icone" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
            </div>
            @if ($ver === 'fila')
                <h2>Está tudo aprovado</h2>
                <p>Não há posts à espera. Os próximos chegam sozinhos do calendário, ou pode pedir um já.</p>
                <div class="vazio-acoes">
                    <button type="button" class="btn btn-principal" data-abrir-dialogo="dlg-pedir">Pedir um post à IA</button>
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
</div>

{{-- "Pedir um post à IA": abre por cima da página (volta a abrir sozinho se o pedido tiver um erro) --}}
<dialog class="dialogo" id="dlg-pedir" aria-labelledby="dlg-pedir-titulo"
        @if ($errors->hasAny(['topic', 'publish_at', 'formato'])) data-abrir-ao-carregar @endif>
    <form method="POST" action="{{ route('requests.store') }}" class="dialogo-form">
        @csrf
        <div class="dialogo-topo">
            <h2 id="dlg-pedir-titulo">Pedir um post à IA</h2>
            <button type="button" class="dialogo-fechar" data-fechar-dialogo aria-label="Fechar">×</button>
        </div>
        <div class="dialogo-corpo">
            <label for="topic">Tema <small>A IA escreve o post, faz a imagem e põe-no em “Para aprovar” em 1 a 2 minutos.</small></label>
            <textarea id="topic" name="topic" rows="3" required maxlength="2000" autofocus
                      placeholder="Ex.: aviso de fecho no feriado de 1 de dezembro">{{ old('topic') }}</textarea>
            @error('topic')<div class="erro">{{ $message }}</div>@enderror

            <label for="publish_at">Publicar em <small>opcional: sem data, a IA escolhe</small></label>
            <input type="datetime-local" id="publish_at" name="publish_at" value="{{ old('publish_at') }}" min="{{ now()->format('Y-m-d\TH:i') }}">
            @error('publish_at')<div class="erro">{{ $message }}</div>@enderror

            <fieldset class="formato-pedido">
                <legend>Formato</legend>
                <label><input type="radio" name="formato" value="imagem" @checked(old('formato', 'imagem') === 'imagem')> Imagem única</label>
                <label><input type="radio" name="formato" value="carrossel" @checked(old('formato') === 'carrossel')> Carrossel <small>(capa + um slide por ponto)</small></label>
            </fieldset>
        </div>
        <div class="dialogo-acoes">
            <button type="button" class="btn" data-fechar-dialogo>Cancelar</button>
            <button type="submit" class="btn btn-principal">Pedir post</button>
        </div>
    </form>
</dialog>

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
