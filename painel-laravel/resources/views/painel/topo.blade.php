<header class="topo">
    <a class="marca" href="{{ route('painel') }}"><b>NEXUS</b><span>Redes sociais</span></a>
    <div class="utilizador">
        <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
        <span>{{ auth()->user()->name }}</span>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sair</button></form>
    </div>
</header>
