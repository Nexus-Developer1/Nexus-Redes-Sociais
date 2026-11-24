@extends('layout')

@section('title', 'Notícias de IA')

@section('body')
@php
    $dia = fn ($d) => $d?->locale('pt_PT')->translatedFormat('j \d\e F');
    // "Pedir um post sobre isto": vai para a IA como um pedido normal (fica em "Para aprovar"; nada sai sem aprovação)
    $tema = fn (string $t) => mb_substr($t, 0, 1900);
    // Separador "Notícias": símbolo de cada fonte, as iniciais com a cor da marca (as outras ficam a verde NEXUS)
    $marcas = [
        'OpenAI' => ['OA', '#111827'], 'Google AI' => ['G', '#1a73e8'], 'NVIDIA' => ['NV', '#76b900'],
        'Hugging Face' => ['HF', '#f59e0b'], 'Microsoft' => ['MS', '#0078d4'], 'AWS Machine Learning' => ['AWS', '#ff9900'],
        'TechCrunch' => ['TC', '#0a9e01'], 'The Verge' => ['V', '#e2127a'], 'MIT Technology Review' => ['MIT', '#a31f34'],
    ];
    $simbolo = fn (string $f) => $marcas[$f] ?? [mb_strtoupper(mb_substr($f, 0, 2)), '#16a34a'];
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
                // O título sem "Resumo de IA:" (a página já diz o que é)
                $titulo = trim(preg_replace('/^\s*(resumo( semanal)?( de| das not[ií]cias de)? ia\s*[:\-–]\s*)/iu', '', $c['titulo'] ?? '')) ?: 'Notícias de IA';
            @endphp
            {{-- Faixa escura no topo (cores do menu lateral): o período, o título e o panorama --}}
            <header class="resumo-faixa">
                <div class="resumo-faixa-texto">
                    <span class="resumo-faixa-periodo">{{ $dia($resumo->inicio) }} a {{ $dia($resumo->fim) }}</span>
                    <h2>{{ $titulo }}</h2>
                    @if (! empty($c['introducao']))<p>{{ $c['introducao'] }}</p>@endif
                </div>
                <dl class="resumo-faixa-numeros">
                    <div><dt>notícias</dt><dd>{{ $resumo->n_noticias }}</dd></div>
                    <div><dt>fontes</dt><dd>{{ $fontesDoResumo }}</dd></div>
                    <div><dt>destaques</dt><dd>{{ count($c['destaques'] ?? []) }}</dd></div>
                </dl>
            </header>

            {{-- Grelha de cartões: primeiro o que interessa à NEXUS, depois um cartão por destaque --}}
            <div class="cartoes-ia">
                @if (! empty($c['para_a_nexus']))
                    <article class="cartao-ia cartao-nexus">
                        <span class="cartao-ia-etiqueta">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2z"/></svg>
                            Para a NEXUS
                        </span>
                        <p class="cartao-ia-texto">{{ $c['para_a_nexus'] }}</p>
                        <details class="cartao-ia-mais"><summary>Ler tudo</summary><p>{{ $c['para_a_nexus'] }}</p></details>
                    </article>
                @endif

                @foreach ($c['destaques'] ?? [] as $i => $d)
                    @php($deste = collect($d['noticias'] ?? [])->map(fn ($id) => $noticiasDoResumo[$id] ?? null)->filter())
                    <article class="cartao-ia">
                        <div class="cartao-ia-topo">
                            <span class="cartao-ia-num">{{ $i + 1 }}</span>
                            @if ($deste->isNotEmpty())
                                <span class="cartao-ia-fontes">{{ $deste->count() }} {{ $deste->count() === 1 ? 'fonte' : 'fontes' }} · {{ $deste->pluck('fonte')->unique()->implode(', ') }}</span>
                            @endif
                        </div>
                        <h3>{{ $d['titulo'] }}</h3>
                        <p class="cartao-ia-texto">{{ $d['resumo'] }}</p>
                        <details class="cartao-ia-mais">
                            <summary>Ler tudo e ver as fontes</summary>
                            <p>{{ $d['resumo'] }}</p>
                            @if ($deste->isNotEmpty())
                                <ul class="cartao-ia-links">
                                    @foreach ($deste as $n)
                                        <li><a href="{{ $n->link }}" target="_blank" rel="noopener noreferrer">{{ $n->titulo }} ↗</a> <small>{{ $n->fonte }}</small></li>
                                    @endforeach
                                </ul>
                            @endif
                        </details>
                        <form method="POST" action="{{ route('requests.store') }}" class="cartao-ia-pe" data-a-enviar="A pedir…">
                            @csrf
                            <input type="hidden" name="topic" value="{{ $tema('Post a partir das notícias de IA: '.$d['titulo'].'. '.$d['resumo']) }}">
                            <input type="hidden" name="formato" value="imagem">
                            <button type="submit" class="btn-post" title="Pedir à IA um post sobre este tema">{!! $maisIcone !!} Pedir um post</button>
                        </form>
                    </article>
                @endforeach
            </div>

            @if (! empty($c['ideias_posts']))
                {{-- Ideias de posts: cartões pequenos numa fila --}}
                <section class="ideias-fila">
                    <h3>Ideias de posts para as redes</h3>
                    <div class="ideias-fila-cartoes">
                        @foreach ($c['ideias_posts'] as $ideia)
                            <form method="POST" action="{{ route('requests.store') }}" class="ideia-cartao" data-a-enviar="A pedir…">
                                @csrf
                                <input type="hidden" name="topic" value="{{ $tema($ideia) }}">
                                <input type="hidden" name="formato" value="imagem">
                                <p>{{ $ideia }}</p>
                                <button type="submit" class="btn-post" title="Pedir à IA este post">{!! $maisIcone !!} Pedir este post</button>
                            </form>
                        @endforeach
                    </div>
                </section>
            @endif

            <p class="resumo-ia-nota">Resumo feito pela IA a partir dos títulos e descrições das notícias: confirme na fonte antes de usar um número ou um dado.</p>
        @endif

    @elseif ($ver === 'noticias')
        {{-- Barra: fonte e pesquisa (em vez de 10 etiquetas em duas filas) --}}
        <form method="GET" action="{{ route('noticias') }}" class="noticias-barra" data-filtro-noticias>
            <input type="hidden" name="ver" value="noticias">
            <label class="noticias-campo">
                <span>Fonte</span>
                <select name="fonte">
                    <option value="">Todas as fontes ({{ $totalNoticias }})</option>
                    @foreach ($porFonte as $nome => $n)
                        <option value="{{ $nome }}" @selected($fonte === $nome)>{{ $nome }} ({{ $n }})</option>
                    @endforeach
                </select>
            </label>
            <label class="noticias-campo noticias-procura">
                <span>Procurar</span>
                <input type="search" name="q" value="{{ $procura }}" placeholder="Ex.: GPU, agentes, segurança…">
            </label>
            <button type="submit" class="btn">Filtrar</button>
            @if ($fonte || $procura !== '')
                <a class="btn btn-link" href="{{ route('noticias', ['ver' => 'noticias']) }}">Limpar</a>
            @endif
        </form>

        @if ($noticias->isEmpty())
            <div class="vazio">
                <h2>{{ $fonte || $procura !== '' ? 'Nenhuma notícia com estes filtros' : 'Ainda não há notícias recolhidas' }}</h2>
                <p>{{ $fonte || $procura !== '' ? 'Experimente outra fonte ou outra palavra.' : 'Carregue em “Recolher agora”.' }}</p>
            </div>
        @else
            @foreach ($noticias->groupBy(fn ($n) => $n->publicada_em?->format('Y-m-d') ?? '—') as $quando => $doDia)
                <section class="noticias-dia">
                    <h3>
                        {{ $quando === '—' ? 'Sem data' : ucfirst(\Illuminate\Support\Carbon::parse($quando)->locale('pt_PT')->translatedFormat('l, j \d\e F')) }}
                        <span>{{ $doDia->count() }}</span>
                    </h3>
                    <div class="noticias-cartoes">
                        @foreach ($doDia as $n)
                            @php([$iniciais, $cor] = $simbolo($n->fonte))
                            <a class="noticia-cartao" href="{{ $n->link }}" target="_blank" rel="noopener noreferrer">
                                <span class="noticia-simbolo" style="background: {{ $cor }}" aria-hidden="true">{{ $iniciais }}</span>
                                <span class="noticia-texto">
                                    <b>{{ $n->titulo }}</b>
                                    @if ($n->descricao)<span class="noticia-desc">{{ $n->descricao }}</span>@endif
                                    <small>{{ $n->fonte }}{{ $n->publicada_em ? ' · '.$n->publicada_em->format('H:i') : '' }} ↗</small>
                                </span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
            @if ($noticias->previousPageUrl() || $noticias->nextPageUrl())
                <nav class="paginas" aria-label="Páginas">
                    @if ($noticias->previousPageUrl())<a class="btn" href="{{ $noticias->previousPageUrl() }}">‹ Mais recentes</a>@endif
                    <span>Página {{ $noticias->currentPage() }}</span>
                    @if ($noticias->nextPageUrl())<a class="btn" href="{{ $noticias->nextPageUrl() }}">Mais antigas ›</a>@endif
                </nav>
            @endif
        @endif
        <script>
        // Mudar a fonte filtra logo (a pesquisa continua com "Filtrar" ou Enter).
        document.querySelectorAll('[data-filtro-noticias] select').forEach(function (s) {
            s.addEventListener('change', function () { s.form.submit(); });
        });
        </script>

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
