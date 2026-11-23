{{-- Cabeçalho igual em todas as páginas: título e uma frase à esquerda, os botões da página à direita. --}}
@props(['titulo', 'sub' => null])
<header {{ $attributes->merge(['class' => 'cabecalho']) }}>
    <div class="cabecalho-texto">
        <h1>{{ $titulo }}</h1>
        @if ($sub)<p class="sub">{{ $sub }}</p>@endif
    </div>
    @if (trim($slot) !== '')
        <div class="cabecalho-acoes">{{ $slot }}</div>
    @endif
</header>
