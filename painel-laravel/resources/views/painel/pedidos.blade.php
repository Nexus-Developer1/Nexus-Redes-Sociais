@if ($requests->isEmpty())
    <div class="vazio">Ainda não há pedidos. Use a caixa “Pedir um post”.</div>
@else
    <div class="pedidos">
        @foreach ($requests as $req)
            <div class="caixa">
                <div>
                    <div style="font-weight:600">{{ $req->topic }}</div>
                    <div class="origem">
                        {{ $req->user?->name ?? '—' }} · {{ $req->created_at->locale('pt_PT')->translatedFormat('j M, H:i') }}
                    </div>
                </div>
                <span class="estado {{ $req->status === 'done' ? 'estado-approved' : ($req->status === 'in_progress' ? 'estado-creating' : 'estado-pending') }}">
                    {{ \App\Models\PostRequest::LABELS[$req->status] ?? $req->status }}
                </span>
            </div>
        @endforeach
    </div>
@endif
