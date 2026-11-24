{{-- Uma notícia de um parceiro tecnológico (vertente "Parceiros tecnológicos" das Notícias): ligação para a fonte e
     "Pedir um post" (um pedido normal à IA: fica em "Para aprovar", nada sai sem aprovação). O texto vem de fora: só como texto. --}}
<article class="noticia-cartao noticia-parceiro">
    @if ($u = $logoMarca($n->fonte))
        <img class="noticia-marca" src="{{ $u }}" alt="{{ $n->fonte }}" loading="lazy">
    @else
        <span class="noticia-logo noticia-iniciais" aria-hidden="true">{{ mb_strtoupper(mb_substr($n->fonte, 0, 3)) }}</span>
    @endif
    <span class="noticia-texto">
        <a href="{{ $n->link }}" target="_blank" rel="noopener noreferrer"><b>{{ $n->titulo }}</b></a>
        @if ($n->descricao)<span class="noticia-desc">{{ $n->descricao }}</span>@endif
        <small>{{ $n->fonte }}{{ $n->publicada_em ? ' · '.$dia($n->publicada_em) : '' }}</small>
    </span>
    <form method="POST" action="{{ route('requests.store') }}" data-a-enviar="A pedir…">
        @csrf
        <input type="hidden" name="topic" value="{{ $tema('Post sobre uma novidade do nosso parceiro '.$n->fonte.' (a NEXUS vende e instala as soluções desta marca): '.$n->titulo.'. '.$n->descricao) }}">
        <input type="hidden" name="formato" value="imagem">
        <button type="submit" class="btn-post" title="Pedir à IA um post sobre esta novidade">{!! $maisIcone !!} Post</button>
    </form>
</article>
