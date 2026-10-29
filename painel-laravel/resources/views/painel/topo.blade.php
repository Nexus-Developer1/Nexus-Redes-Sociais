<header class="topo">
    <a class="marca" href="{{ route('painel') }}"><img src="{{ asset('modelos/logo-nexus.png') }}" alt="NEXUS" width="150" height="28"><span>Redes sociais</span></a>
    <div class="utilizador">
        <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
        <span>{{ auth()->user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="sair" title="Terminar sessão"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg> Sair</button></form>
    </div>
</header>
