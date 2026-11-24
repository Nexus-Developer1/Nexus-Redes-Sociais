{{-- Barra lateral escura do ADN Nexus (igual à do portal e da Nexus Infra): marca, secções do painel e quem está ligado. --}}
@php
    $porEstado = \App\Models\Post::contagens(); // contado uma vez por página (o resumo da fila usa o mesmo)
    $contar = fn (array $estados) => collect($estados)->sum(fn ($s) => $porEstado[$s] ?? 0);
    $verAtual = request()->routeIs('painel') ? request()->query('ver', 'fila') : null;
    $pedidosAbertos = \App\Models\PostRequest::where('status', '!=', 'done')->count();
    $pessoa = auth()->user();
    $iniciais = collect(preg_split('/\s+/', trim($pessoa->name)))->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->implode('');
    $icones = [
        'fila' => '<path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>',
        'agendados' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'publicados' => '<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>',
        'rejeitados' => '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>',
        'ideias' => '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.4 1 1.1 1 1.8V16h5v-.3c0-.7.4-1.4 1-1.8A6 6 0 0 0 12 3z"/>',
        'pedidos' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    ];
@endphp
<aside class="lateral" id="menu-lateral" data-lateral>
    <div class="lateral__topo">
        <a class="lateral__marca" href="{{ route('painel') }}">
            <img src="{{ asset('modelos/logo-nexus.png') }}" alt="Nexus" width="150" height="28">
            <span>Redes sociais</span>
        </a>
        <button class="lateral__fechar" type="button" data-fechar-lateral aria-label="Fechar menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <nav class="lateral__nav" aria-label="Navegação principal">
        <p class="lateral__grupo">Posts</p>
        @foreach (\App\Http\Controllers\PainelController::SECTIONS as $chave => [$titulo, $estados])
            @php($num = $contar($estados))
            <a class="nav-item {{ $verAtual === $chave ? 'nav-item--activo' : '' }}" href="{{ route('painel', $chave === 'fila' ? [] : ['ver' => $chave]) }}"
               @if ($verAtual === $chave) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icones[$chave] !!}</svg>
                <span>{{ $titulo }}</span>
                @if ($num > 0)<span class="nav-num {{ $chave === 'fila' ? 'nav-num--forte' : '' }}">{{ $num }}</span>@endif
            </a>
        @endforeach
        <a class="nav-item {{ $verAtual === 'pedidos' ? 'nav-item--activo' : '' }}" href="{{ route('painel', ['ver' => 'pedidos']) }}"
           @if ($verAtual === 'pedidos') aria-current="page" @endif>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icones['pedidos'] !!}</svg>
            <span>Pedidos de posts</span>
            @if ($pedidosAbertos > 0)<span class="nav-num">{{ $pedidosAbertos }}</span>@endif
        </a>

        <p class="lateral__grupo">Planear</p>
        <a class="nav-item {{ request()->routeIs('calendario') ? 'nav-item--activo' : '' }}" href="{{ route('calendario') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
            <span>Calendário</span>
        </a>
        <a class="nav-item {{ request()->routeIs('biblioteca') ? 'nav-item--activo' : '' }}" href="{{ route('biblioteca') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 17l-5-5-9 8"/></svg>
            <span>Biblioteca</span>
        </a>
        <a class="nav-item {{ request()->routeIs('parceiros*') ? 'nav-item--activo' : '' }}" href="{{ route('parceiros') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
            <span>Parceiros</span>
        </a>

        @if ($pessoa->is_admin)
            <p class="lateral__grupo">Gestão</p>
            <a class="nav-item {{ request()->routeIs('users.*') ? 'nav-item--activo' : '' }}" href="{{ route('users.index') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6"/></svg>
                <span>Utilizadores</span>
            </a>
        @endif

        <a class="btn btn-principal lateral__criar" href="{{ route('posts.create') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            Criar post
        </a>
    </nav>

    <div class="lateral__pessoa">
        <a class="lateral__avatar" href="{{ route('conta') }}" title="A minha conta">{{ $iniciais }}</a>
        <a class="lateral__quem" href="{{ route('conta') }}" title="A minha conta">
            <span class="lateral__nome">{{ $pessoa->name }}</span>
            <span class="lateral__papel">{{ $pessoa->email }}</span>
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="lateral__sair" title="Terminar sessão" aria-label="Terminar sessão">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            </button>
        </form>
    </div>
</aside>
