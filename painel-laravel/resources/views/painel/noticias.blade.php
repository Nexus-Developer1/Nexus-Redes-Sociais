@extends('layout')

@section('title', 'Notícias de IA')

@section('body')
@php
    $data = fn ($d) => $d?->locale('pt_PT')->translatedFormat('j \d\e F');
    // "Pedir um post sobre isto": vai para a IA como um pedido normal (fica em "Para aprovar", nada sai sem aprovação)
    $pedir = fn (string $tema) => mb_substr($tema, 0, 1900);
@endphp

<div class="pagina">
    <x-cabecalho titulo="Notícias de IA" :sub="'Um agente recolhe as notícias de '.$fontes.' fontes à segunda e à quinta-feira, às 08:00, e a IA faz o resumo.'">
        <form method="POST" action="{{ route('noticias.recolher') }}" data-a-enviar="A pedir…">
            @csrf
            <button type="submit" class="btn btn-principal" @disabled($aTrabalhar)>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
                Recolher agora
            </button>
        </form>
    </x-cabecalho>

    @if (session('ok'))<div class="aviso" role="status">{{ session('ok') }}</div>@endif
    @if ($aTrabalhar)
        <p class="faixa-info" role="status" data-recarregar="20">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            O agente está a recolher as notícias e a fazer o resumo. A página atualiza-se sozinha.
        </p>
    @endif

    @if (! $resumo)
        <div class="vazio">
            <h2>Ainda não há nenhum resumo</h2>
            <p>O primeiro sai na próxima segunda ou quinta-feira às 08:00. Para não esperar, carregue em “Recolher agora”.</p>
        </div>
        @if ($noticias->isNotEmpty())
            <section class="bloco noticias-lado">
                <h2>Notícias recolhidas</h2>
                <ul class="noticias-lista">
                    @foreach ($noticias as $n)
                        <li>
                            <a href="{{ $n->link }}" target="_blank" rel="noopener noreferrer">{{ $n->titulo }}</a>
                            <small>{{ $n->fonte }}{{ $n->publicada_em ? ' · '.$data($n->publicada_em) : '' }}</small>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @else
        <div class="noticias-grelha">
            <article class="bloco resumo-ia">
                <header class="resumo-ia-topo">
                    <span class="resumo-ia-periodo">{{ $data($resumo->inicio) }} a {{ $data($resumo->fim) }} · {{ $resumo->n_noticias }} notícias</span>
                    <h2>{{ $resumo->conteudo['titulo'] ?? 'Notícias de IA' }}</h2>
                    @if (! empty($resumo->conteudo['introducao']))<p class="resumo-ia-intro">{{ $resumo->conteudo['introducao'] }}</p>@endif
                </header>

                <ol class="destaques-ia">
                    @foreach ($resumo->conteudo['destaques'] ?? [] as $d)
                        <li class="destaque-ia">
                            <h3>{{ $d['titulo'] }}</h3>
                            <p>{{ $d['resumo'] }}</p>
                            <div class="destaque-ia-pe">
                                @php($deste = collect($d['noticias'] ?? [])->map(fn ($id) => $noticiasDoResumo[$id] ?? null)->filter())
                                @if ($deste->isNotEmpty())
                                    {{-- "14 fontes · The Verge, TechCrunch": ao abrir, o título de cada notícia com o link --}}
                                    <details class="destaque-ia-fontes">
                                        <summary>{{ $deste->count() }} {{ $deste->count() === 1 ? 'fonte' : 'fontes' }} · {{ $deste->pluck('fonte')->unique()->implode(', ') }}</summary>
                                        <ul>
                                            @foreach ($deste as $n)
                                                <li><a href="{{ $n->link }}" target="_blank" rel="noopener noreferrer">{{ $n->titulo }} ↗</a> <small>{{ $n->fonte }}</small></li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @else
                                    <span></span>
                                @endif
                                <form method="POST" action="{{ route('requests.store') }}" data-a-enviar="A pedir…">
                                    @csrf
                                    <input type="hidden" name="topic" value="{{ $pedir('Post a partir das notícias de IA: '.$d['titulo'].'. '.$d['resumo']) }}">
                                    <input type="hidden" name="formato" value="imagem">
                                    <button type="submit" class="btn pequeno">Pedir um post sobre isto</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ol>

                @if (! empty($resumo->conteudo['para_a_nexus']))
                    <section class="para-nexus">
                        <h3>O que isto significa para a NEXUS</h3>
                        <p>{{ $resumo->conteudo['para_a_nexus'] }}</p>
                    </section>
                @endif

                @if (! empty($resumo->conteudo['ideias_posts']))
                    <section class="ideias-ia">
                        <h3>Ideias de posts</h3>
                        <ul>
                            @foreach ($resumo->conteudo['ideias_posts'] as $ideia)
                                <li>
                                    <span>{{ $ideia }}</span>
                                    <form method="POST" action="{{ route('requests.store') }}" data-a-enviar="A pedir…">
                                        @csrf
                                        <input type="hidden" name="topic" value="{{ $pedir($ideia) }}">
                                        <input type="hidden" name="formato" value="imagem">
                                        <button type="submit" class="btn pequeno">Pedir este post</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
                <p class="resumo-ia-nota">Resumo feito pela IA a partir dos títulos e descrições das notícias: confirme na fonte antes de usar um dado.</p>
            </article>

            <aside class="noticias-lado">
                @if ($resumos->count() > 1)
                    <section class="bloco">
                        <h2>Resumos anteriores</h2>
                        <ul class="resumos-lista">
                            @foreach ($resumos as $r)
                                <li>
                                    <a href="{{ route('noticias', ['resumo' => $r->id]) }}" @if ($r->is($resumo)) aria-current="true" @endif>
                                        <b>{{ $r->conteudo['titulo'] ?? 'Resumo' }}</b>
                                        <small>{{ $data($r->inicio) }} a {{ $data($r->fim) }}</small>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
                <section class="bloco">
                    <h2>Notícias recolhidas</h2>
                    <ul class="noticias-lista">
                        @foreach ($noticias as $n)
                            <li>
                                <a href="{{ $n->link }}" target="_blank" rel="noopener noreferrer">{{ $n->titulo }}</a>
                                <small>{{ $n->fonte }}{{ $n->publicada_em ? ' · '.$data($n->publicada_em) : '' }}</small>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </aside>
        </div>
    @endif
</div>

@if ($aTrabalhar)
<script>
// Enquanto o agente trabalha, a página volta a carregar sozinha (sem perder o que se está a ler: só 20 s depois).
setTimeout(function () { location.reload(); }, 20000);
</script>
@endif
@endsection
