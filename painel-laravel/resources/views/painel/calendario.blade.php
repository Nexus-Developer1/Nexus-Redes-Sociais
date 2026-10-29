@extends('layout')

@section('title', 'Calendário')

@section('body')
@include('painel.topo')

<div class="pagina-calendario">
    <div class="editor-barra">
        <a href="{{ route('painel') }}" class="voltar" title="Voltar ao painel"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg> Painel</a>
        <div class="mes-nav">
            <a class="btn pequeno" href="{{ route('calendario', ['mes' => $anterior]) }}" title="Mês anterior" aria-label="Mês anterior">‹</a>
            <h1>{{ ucfirst($mes->translatedFormat('F Y')) }}</h1>
            <a class="btn pequeno" href="{{ route('calendario', ['mes' => $seguinte]) }}" title="Mês seguinte" aria-label="Mês seguinte">›</a>
            @unless ($mes->isSameMonth(now()))
                <a class="btn pequeno" href="{{ route('calendario') }}">Hoje</a>
            @endunless
        </div>
        <div class="barra-acoes">
            <span class="resumo">
                {{ $totalMes }} {{ $totalMes === 1 ? 'post' : 'posts' }} este mês
                @if ($semData) · <a href="{{ route('painel', ['ver' => 'ideias']) }}">{{ $semData }} sem data</a>@endif
            </span>
            <a class="btn btn-principal" href="{{ route('posts.create') }}">+ Criar post</a>
        </div>
    </div>

    <div class="legenda-estados" aria-label="Legenda">
        @foreach ([\App\Models\Post::IDEA, \App\Models\Post::CREATING, \App\Models\Post::PENDING, \App\Models\Post::APPROVED, \App\Models\Post::PUBLISHED, \App\Models\Post::REJECTED] as $e)
            <span class="estado estado-{{ $e }}">{{ \App\Models\Post::LABELS[$e] }}</span>
        @endforeach
        <span class="legenda-feriado">Feriado</span>
    </div>

    <div class="calendario">
        @foreach (['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'] as $nome)
            <div class="cal-cabeca">{{ $nome }}</div>
        @endforeach

        @foreach ($dias as $dia)
            @php($d = $dia['data']->locale('pt_PT'))
            <div @class([
                'cal-dia',
                'fora' => ! $dia['doMes'],
                'hoje' => $dia['hoje'],
                'passado' => $dia['passado'],
                'fim-semana' => $d->isWeekend(),
                'tem-feriado' => $dia['feriado'],
                'vazio' => $dia['posts']->isEmpty(),
            ])>
                <div class="cal-topo">
                    <span class="num">{{ $d->format('j') }}<span class="dia-semana"> {{ rtrim($d->translatedFormat('D'), '.') }}</span></span>
                    @if (! $dia['passado'])
                        <a class="mais" href="{{ route('posts.create', ['data' => $dia['chave']]) }}"
                           title="Criar post para {{ $d->translatedFormat('j \d\e F') }}" aria-label="Criar post para {{ $d->translatedFormat('j \d\e F') }}">+</a>
                    @endif
                </div>
                @if ($dia['feriado'])
                    <div class="feriado">{{ $dia['feriado'] }}</div>
                @endif
                @foreach ($dia['posts'] as $p)
                    <a class="cal-post estado-{{ $p->status }}" href="{{ route('posts.edit', $p) }}"
                       title="{{ $p->title }} · {{ $p->statusLabel() }} · {{ implode(', ', $p->networks ?? []) }}">
                        <b>{{ $p->scheduled_at->format('H:i') }}</b> {{ $p->title }}
                    </a>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
@endsection
