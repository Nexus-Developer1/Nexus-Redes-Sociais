@extends('layout')

@section('title', 'Notícias de IA')

@section('body')
@php
    $dia = fn ($d) => $d?->locale('pt_PT')->translatedFormat('j \d\e F');
    // "Pedir um post sobre isto": vai para a IA como um pedido normal (fica em "Para aprovar"; nada sai sem aprovação)
    $tema = fn (string $t) => mb_substr($t, 0, 1900);
    $maisIcone = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
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
        <p class="faixa-info" role="status">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            O agente está a recolher as notícias e a fazer o resumo. A página atualiza-se sozinha.
        </p>
    @endif

    {{-- Separadores: o resumo fica sozinho; as notícias e os resumos anteriores à parte --}}
    <nav class="separadores" aria-label="Notícias de IA">
        <a href="{{ route('noticias', array_filter(['resumo' => request('resumo')])) }}" @if ($ver === 'resumo') aria-current="page" @endif>Resumo</a>
        <a href="{{ route('noticias', ['ver' => 'noticias']) }}" @if ($ver === 'noticias') aria-current="page" @endif>Notícias <span>{{ $totalNoticias }}</span></a>
        <a href="{{ route('noticias', ['ver' => 'anteriores']) }}" @if ($ver === 'anteriores') aria-current="page" @endif>Resumos anteriores <span>{{ $resumos->count() }}</span></a>
    </nav>

    @if ($ver === 'resumo')
        @if (! $resumo)
            <div class="vazio">
                <h2>Ainda não há nenhum resumo</h2>
                <p>O primeiro sai na próxima segunda ou quinta-feira às 08:00. Para não esperar, carregue em “Recolher agora”.</p>
            </div>
        @else
            @php
                $c = $resumo->conteudo;
                // "Para a NEXUS": a 1.ª frase à vista, o resto em "Ler mais"
                [$nexus1, $nexusResto] = array_pad(preg_split('/(?<=[.!?])\s+/u', trim($c['para_a_nexus'] ?? ''), 2), 2, '');
            @endphp
            {{-- Ao abrir, só o essencial: título e panorama; o resto abre quando se carrega --}}
            <div class="resumo-simples">
                <header class="resumo-topo">
                    <span class="resumo-ia-periodo">{{ $dia($resumo->inicio) }} a {{ $dia($resumo->fim) }}</span>
                    <h2>{{ $c['titulo'] ?? 'Notícias de IA' }}</h2>
                    @if (! empty($c['introducao']))<p class="resumo-ia-intro">{{ $c['introducao'] }}</p>@endif
                    <p class="resumo-meta">{{ $resumo->n_noticias }} notícias de {{ $fontesDoResumo }} {{ $fontesDoResumo === 1 ? 'fonte' : 'fontes' }}</p>
                </header>

                @if ($nexus1 !== '')
                    <section class="para-nexus">
                        <h3>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2z"/></svg>
                            O que significa para a NEXUS
                        </h3>
                        <p>{{ $nexus1 }}</p>
                        @if ($nexusResto !== '')
                            <details class="ler-mais">
                                <summary>Ler mais</summary>
                                <p>{{ $nexusResto }}</p>
                            </details>
                        @endif
                    </section>
                @endif

                {{-- Destaques: só os títulos; cada um abre com o resumo, as fontes e o "+ Post" --}}
                <section>
                    <h3 class="secao-titulo">Destaques <span>{{ count($c['destaques'] ?? []) }}</span></h3>
                    <ol class="destaques-lista">
                        @foreach ($c['destaques'] ?? [] as $i => $d)
                            @php($deste = collect($d['noticias'] ?? [])->map(fn ($id) => $noticiasDoResumo[$id] ?? null)->filter())
                            <li>
                                <details class="destaque-linha">
                                    <summary>
                                        <span class="destaque-num">{{ $i + 1 }}</span>
                                        <span class="destaque-titulo">{{ $d['titulo'] }}</span>
                                        <svg class="destaque-seta" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                                    </summary>
                                    <div class="destaque-corpo">
                                        <p>{{ $d['resumo'] }}</p>
                                        @if ($deste->isNotEmpty())
                                            <ul class="destaque-fontes">
                                                @foreach ($deste as $n)
                                                    <li><a href="{{ $n->link }}" target="_blank" rel="noopener noreferrer">{{ $n->titulo }} ↗</a> <small>{{ $n->fonte }}</small></li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        <form method="POST" action="{{ route('requests.store') }}" data-a-enviar="A pedir…">
                                            @csrf
                                            <input type="hidden" name="topic" value="{{ $tema('Post a partir das notícias de IA: '.$d['titulo'].'. '.$d['resumo']) }}">
                                            <input type="hidden" name="formato" value="imagem">
                                            <button type="submit" class="btn-post" title="Pedir à IA um post sobre este tema">{!! $maisIcone !!} Pedir um post sobre isto</button>
                                        </form>
                                    </div>
                                </details>
                            </li>
                        @endforeach
                    </ol>
                </section>

                @if (! empty($c['ideias_posts']))
                    <details class="ideias-fechadas">
                        <summary>{{ count($c['ideias_posts']) }} {{ count($c['ideias_posts']) === 1 ? 'ideia de post' : 'ideias de posts' }} para as redes</summary>
                        <ul>
                            @foreach ($c['ideias_posts'] as $ideia)
                                <li>
                                    <span>{{ $ideia }}</span>
                                    <form method="POST" action="{{ route('requests.store') }}" data-a-enviar="A pedir…">
                                        @csrf
                                        <input type="hidden" name="topic" value="{{ $tema($ideia) }}">
                                        <input type="hidden" name="formato" value="imagem">
                                        <button type="submit" class="btn-post" title="Pedir à IA este post">{!! $maisIcone !!} Post</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                <p class="resumo-ia-nota">Resumo feito pela IA a partir dos títulos e descrições das notícias: confirme na fonte antes de usar um número ou um dado.</p>
            </div>
        @endif

    @elseif ($ver === 'noticias')
        {{-- Todas as notícias recolhidas: filtro por fonte e agrupadas por dia --}}
        <div class="filtros-bib filtros-fontes" role="group" aria-label="Fonte">
            <a href="{{ route('noticias', ['ver' => 'noticias']) }}" class="{{ $fonte ? '' : 'ativo' }}">Todas <b>{{ $totalNoticias }}</b></a>
            @foreach ($porFonte as $nome => $n)
                <a href="{{ route('noticias', ['ver' => 'noticias', 'fonte' => $nome]) }}" class="{{ $fonte === $nome ? 'ativo' : '' }}">{{ $nome }} <b>{{ $n }}</b></a>
            @endforeach
        </div>
        @if ($noticias->isEmpty())
            <div class="vazio"><h2>Ainda não há notícias recolhidas</h2><p>Carregue em “Recolher agora”.</p></div>
        @else
            <div class="bloco noticias-dias">
                @foreach ($noticias->groupBy(fn ($n) => $n->publicada_em?->format('Y-m-d') ?? '—') as $quando => $doDia)
                    <section>
                        <h3>{{ $quando === '—' ? 'Sem data' : ucfirst(\Illuminate\Support\Carbon::parse($quando)->locale('pt_PT')->translatedFormat('l, j \d\e F')) }}</h3>
                        <ul class="noticias-lista">
                            @foreach ($doDia as $n)
                                <li>
                                    <span class="fonte-etiqueta">{{ $n->fonte }}</span>
                                    <a href="{{ $n->link }}" target="_blank" rel="noopener noreferrer">{{ $n->titulo }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
            @if ($noticias->previousPageUrl() || $noticias->nextPageUrl())
                <nav class="paginas" aria-label="Páginas">
                    @if ($noticias->previousPageUrl())<a class="btn" href="{{ $noticias->previousPageUrl() }}">‹ Mais recentes</a>@endif
                    <span>Página {{ $noticias->currentPage() }}</span>
                    @if ($noticias->nextPageUrl())<a class="btn" href="{{ $noticias->nextPageUrl() }}">Mais antigas ›</a>@endif
                </nav>
            @endif
        @endif

    @else
        {{-- Resumos anteriores --}}
        @if ($resumos->isEmpty())
            <div class="vazio"><h2>Ainda não há resumos</h2></div>
        @else
            <ul class="resumos-cartoes">
                @foreach ($resumos as $r)
                    <li>
                        <a class="bloco" href="{{ route('noticias', ['resumo' => $r->id]) }}">
                            <span class="resumo-ia-periodo">{{ $dia($r->inicio) }} a {{ $dia($r->fim) }}</span>
                            <b>{{ $r->conteudo['titulo'] ?? 'Resumo' }}</b>
                            <small>{{ $r->n_noticias }} notícias · {{ count($r->conteudo['destaques'] ?? []) }} destaques</small>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>

@if ($aTrabalhar)
<script>
// Enquanto o agente trabalha, a página volta a carregar sozinha (só 20 s depois, para dar tempo de ler).
setTimeout(function () { location.reload(); }, 20000);
</script>
@endif
@endsection
