<article class="post" id="post-{{ $post->id }}">
    @if ($post->image_path)
        <img src="{{ asset($post->image_path) }}" alt="{{ $post->image_alt ?? $post->title }}" loading="lazy">
    @else
        <div class="sem-imagem">Sem imagem</div>
    @endif

    <div class="corpo">
        <div class="cabeca">
            <h2>{{ $post->title }}</h2>
            <span class="estado estado-{{ $post->status }}">{{ $post->statusLabel() }}</span>
        </div>

        <div class="meta">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0a6e31" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg>
            <span>{{ $post->whenLabel() }}</span>
            <span style="color:#9aa79f">·</span>
            @foreach ($post->networks as $net)
                <span class="rede">{{ $net }}</span>
            @endforeach
        </div>

        @if ($post->caption)
            <div class="legenda">{{ $post->caption }}</div>
        @endif

        @if ($post->source)
            <p class="origem">{{ $post->source }}</p>
        @endif

        @if ($post->status === \App\Models\Post::REVISING && $post->change_request)
            <div class="pedido-alteracao"><strong>Alteração pedida:</strong> {{ $post->change_request }}</div>
        @endif

        @if ($post->status === \App\Models\Post::PENDING)
            <div class="acoes">
                <form method="POST" action="{{ route('posts.approve', $post) }}">
                    @csrf
                    <button type="submit" class="btn btn-principal">Aprovar e agendar</button>
                </form>
                <details class="alterar">
                    <summary class="btn btn-contorno">Pedir alteração</summary>
                    <form class="form-alterar" method="POST" action="{{ route('posts.change', $post) }}">
                        @csrf
                        <label for="alt-{{ $post->id }}">O que quer mudar?</label>
                        <textarea id="alt-{{ $post->id }}" name="comment" rows="3" required maxlength="2000"
                                  placeholder="Ex.: muda o título para algo mais direto e usa um fundo mais claro"></textarea>
                        <div class="acoes">
                            <button type="submit" class="btn btn-principal">Enviar ao Claude</button>
                            <button type="button" class="btn" onclick="this.closest('details').open = false">Cancelar</button>
                        </div>
                    </form>
                </details>
                <form method="POST" action="{{ route('posts.reject', $post) }}"
                      onsubmit="return confirm('Rejeitar este post? Não vai ser publicado.')">
                    @csrf
                    <button type="submit" class="btn btn-perigo">Rejeitar</button>
                </form>
            </div>
        @elseif (in_array($post->status, [\App\Models\Post::APPROVED, \App\Models\Post::REJECTED, \App\Models\Post::REVISING], true))
            <div class="acoes" style="align-items:center;font-size:14px;color:#33443a">
                <span>
                    @switch($post->status)
                        @case(\App\Models\Post::APPROVED) Vai ser publicado automaticamente à hora marcada. @break
                        @case(\App\Models\Post::REJECTED) Este rascunho não vai ser publicado. @break
                        @default O Claude está a preparar uma nova versão com as suas indicações.
                    @endswitch
                </span>
                <form method="POST" action="{{ route('posts.undo', $post) }}">
                    @csrf
                    <button type="submit" class="btn btn-link">Desfazer</button>
                </form>
            </div>
        @endif

        @if ($post->reviews->isNotEmpty())
            <div class="historico">
                Histórico:
                <ul>
                    @foreach ($post->reviews->take(5) as $r)
                        <li>
                            {{ $r->created_at->locale('pt_PT')->translatedFormat('j M, H:i') }} —
                            {{ $r->user?->name ?? 'Sistema' }} {{ \App\Models\PostReview::LABELS[$r->action] ?? $r->action }}@if ($r->comment): “{{ $r->comment }}”@endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</article>
