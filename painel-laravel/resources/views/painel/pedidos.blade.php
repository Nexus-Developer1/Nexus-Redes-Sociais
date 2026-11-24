{{--
  Topo de "Ideias e em criação": os pedidos à IA ainda sem post ("Pedir um post à IA"), a fazer agora ou que falharam.
  (Antes era a página "Pedidos de posts"; os pedidos que já viraram post estão na lista ou em "Para aprovar".)
--}}
<section class="pedidos-ia" aria-label="Pedidos à IA">
    <h2 class="pedidos-ia-titulo">Pedidos à IA <span>{{ $requests->count() }}</span></h2>
    <ul>
        @foreach ($requests as $req)
            @php($falhou = $req->status === 'failed')
            <li class="pedido-ia {{ $falhou ? 'pedido-ia--falhou' : '' }}" @unless ($falhou) data-espera="pedido" data-id="{{ $req->id }}" @endunless>
                <span class="pedido-ia-estado" aria-hidden="true">
                    @if ($falhou)
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                    @else
                        <span class="pedido-ia-roda"></span>
                    @endif
                </span>
                <div class="pedido-ia-texto">
                    <b>{{ \Illuminate\Support\Str::limit($req->topic, 160) }}</b>
                    <small>
                        {{ $falhou ? 'A IA não conseguiu fazer o post' : 'A IA está a fazer o post (1 a 2 minutos)' }}
                        · {{ $req->user?->name ?? 'Sistema' }}, {{ $req->created_at->locale('pt_PT')->translatedFormat('j M, H:i') }}
                        @if ($req->carrossel) · carrossel @endif
                        @if ($req->publish_at) · para {{ $req->publish_at->locale('pt_PT')->translatedFormat('D, j M, H:i') }} @endif
                    </small>
                    @if ($falhou && $req->error)<span class="pedido-ia-erro">{{ $req->error }}</span>@endif
                </div>
                @if ($falhou)
                    <form method="POST" action="{{ route('requests.retry', $req) }}" data-a-enviar="A pedir…">
                        @csrf
                        <button type="submit" class="btn pequeno">Tentar outra vez</button>
                    </form>
                @endif
            </li>
        @endforeach
    </ul>
</section>
