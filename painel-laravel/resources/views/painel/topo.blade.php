<header class="topo">
    <a class="marca" href="{{ route('painel') }}"><img src="{{ asset('modelos/logo-nexus.png') }}" alt="NEXUS" width="150" height="28"><span>Redes sociais</span></a>
    <div class="utilizador">
        <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
        <span>{{ auth()->user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sair</button></form>
    </div>
</header>
