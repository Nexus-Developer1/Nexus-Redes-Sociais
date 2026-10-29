@if ($requests->isEmpty())
    <div class="vazio">Ainda não há pedidos. Use a caixa “Pedir um post”.</div>
@else
    <div class="pedidos">
        @foreach ($requests as $req)
            <div class="caixa">
                <div class="pedido-texto">
                    <div style="font-weight:600">{{ $req->topic }}</div>
                    <div class="origem">
                        {{ $req->user?->name ?? '—' }} · pedido a {{ $req->created_at->locale('pt_PT')->translatedFormat('j M, H:i') }}
                        @if ($req->publish_at)
                            · <b>publicar a {{ $req->publish_at->locale('pt_PT')->translatedFormat('D, j M, H:i') }}</b>
                        @endif
                        @if ($req->post)
                            · <a href="{{ route('posts.edit', $req->post) }}">ver o post “{{ $req->post->title }}”</a>
                        @endif
                    </div>
                    @if ($req->error)
                        <div class="pedido-erro">{{ $req->error }}</div>
                    @endif
                </div>
                <div class="pedido-lado">
                    <span class="estado {{ match ($req->status) {
                        'done' => 'estado-approved',
                        'in_progress' => 'estado-creating',
                        'failed' => 'estado-rejected',
                        default => 'estado-pending',
                    } }}">{{ \App\Models\PostRequest::LABELS[$req->status] ?? $req->status }}</span>
                    @if ($req->status === 'failed')
                        <form method="POST" action="{{ route('requests.retry', $req) }}">
                            @csrf
                            <button type="submit" class="btn pequeno">Tentar outra vez</button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
