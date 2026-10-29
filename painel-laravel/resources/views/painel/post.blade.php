<article class="post" id="post-{{ $post->id }}">
    <div class="post-imagem">
        @if ($post->image_path)
            <a href="{{ asset($post->image_path) }}" target="_blank" rel="noopener" title="Abrir a imagem em tamanho real">
                <img src="{{ asset($post->image_path) }}" alt="{{ $post->image_alt ?? $post->title }}" loading="lazy">
            </a>
        @else
            <div class="sem-imagem">Sem imagem</div>
        @endif
        @if ($post->isEditable() && $post->design && app(\App\Services\BancoFotos::class)->configurado())
            {{-- A IA escolhe outra foto do banco de fotos e a imagem é refeita --}}
            <form method="POST" action="{{ route('posts.otherPhoto', $post) }}" class="outra-foto"
                  onsubmit="var b = this.querySelector('button'); b.disabled = true; b.textContent = 'A trocar…';">
                @csrf
                <button type="submit" class="btn" title="Trocar a foto de fundo por outra escolhida pela IA">↻ Outra foto</button>
            </form>
        @endif
    </div>

    <div class="corpo">
        <div class="cabeca">
            <h2>{{ $post->title }}</h2>
            <span class="estado estado-{{ $post->status }}">{{ $post->statusLabel() }}</span>
        </div>

        <div class="meta">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0a6e31" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg>
            @if ($post->isEditable())
                {{-- Clicar na data abre um campo para a mudar logo aqui --}}
                <details class="mudar-data">
                    <summary title="Mudar o dia e a hora de publicação"><span class="quando">{{ $post->whenLabel() }}</span> <span class="mudar">✎ Mudar data</span></summary>
                    <form method="POST" action="{{ route('posts.date', $post) }}">
                        @csrf
                        <input type="datetime-local" name="scheduled_at" required aria-label="Dia e hora de publicação"
                               value="{{ $post->scheduled_at?->format('Y-m-d\TH:i') }}" min="{{ now()->format('Y-m-d\TH:i') }}">
                        <button type="submit" class="btn btn-principal">Guardar</button>
                    </form>
                </details>
            @else
                <span>{{ $post->whenLabel() }}</span>
            @endif
            <span class="sep">·</span>
            @foreach ($post->networks as $net)
                <span class="rede">{{ $net }}</span>
            @endforeach
        </div>

        @if ($post->caption)
            <div class="legenda" data-legenda>{{ $post->caption }}</div>
            <button type="button" class="ver-mais" hidden>Ver legenda completa</button>
        @endif

        @if ($post->source)
            <p class="origem">{{ $post->source }}</p>
        @endif

        @if ($post->status === \App\Models\Post::APPROVED && $post->publish_error)
            <div class="pedido-alteracao erro">
                <strong>A publicação falhou</strong>
                ({{ $post->publish_attempts }} de {{ \App\Models\Post::MAX_PUBLISH_ATTEMPTS }} tentativas{{ $post->publish_attempts >= \App\Models\Post::MAX_PUBLISH_ATTEMPTS ? '; parou de tentar' : '; vai tentar de novo' }}):
                {{ $post->publish_error }}
            </div>
        @endif

        @if ($post->status === \App\Models\Post::REVISING && $post->change_request)
            <div class="pedido-alteracao"><strong>Alteração pedida:</strong> {{ $post->change_request }}</div>
        @endif

        {{-- Botões: o principal à esquerda, o perigoso à direita --}}
        @if ($post->status === \App\Models\Post::PENDING)
            <div class="acoes">
                <form method="POST" action="{{ route('posts.approve', $post) }}">
                    @csrf
                    <button type="submit" class="btn btn-principal" title="Aprovar e agendar para a data marcada">Aprovar</button>
                </form>
                <details class="alterar">
                    <summary class="btn btn-contorno">Pedir alteração</summary>
                    <form class="form-alterar" method="POST" action="{{ route('posts.change', $post) }}">
                        @csrf
                        <label for="alt-{{ $post->id }}">O que quer mudar? A nova versão chega à fila sozinha.</label>
                        <textarea id="alt-{{ $post->id }}" name="comment" rows="3" required maxlength="2000"
                                  placeholder="Ex.: título mais curto e acrescenta o LinkedIn"></textarea>
                        <div class="acoes">
                            <button type="submit" class="btn btn-principal">Enviar pedido</button>
                            <button type="button" class="btn" onclick="this.closest('details').open = false">Cancelar</button>
                        </div>
                    </form>
                </details>
                <a class="btn" href="{{ route('posts.edit', $post) }}">Editar</a>
                <form method="POST" action="{{ route('posts.reject', $post) }}" class="direita"
                      onsubmit="return confirm('Rejeitar este post? Não vai ser publicado.')">
                    @csrf
                    <button type="submit" class="btn btn-perigo">Rejeitar</button>
                </form>
            </div>
        @elseif (in_array($post->status, [\App\Models\Post::IDEA, \App\Models\Post::CREATING], true))
            @if ($post->change_request)
                <div class="pedido-alteracao"><strong>Pediu outro tema:</strong> {{ $post->change_request }}</div>
            @endif
            <div class="acoes">
                <a class="btn btn-principal" href="{{ route('posts.edit', $post) }}">{{ $post->design ? 'Continuar a editar' : 'Fazer este post' }}</a>
                @unless ($post->change_request)
                    <details class="alterar">
                        <summary class="btn btn-contorno">Trocar tema</summary>
                        <form class="form-alterar" method="POST" action="{{ route('posts.swap', $post) }}">
                            @csrf
                            <label for="troca-{{ $post->id }}">Que tema prefere para esta data?</label>
                            <textarea id="troca-{{ $post->id }}" name="comment" rows="3" required maxlength="2000"
                                      placeholder="Ex.: em vez disto, um post sobre as UPS Riello"></textarea>
                            <div class="acoes">
                                <button type="submit" class="btn btn-principal">Enviar pedido</button>
                                <button type="button" class="btn" onclick="this.closest('details').open = false">Cancelar</button>
                            </div>
                        </form>
                    </details>
                @endunless
                <form method="POST" action="{{ route('posts.discard', $post) }}" class="direita"
                      onsubmit="return confirm('Descartar esta ideia?')">
                    @csrf
                    <button type="submit" class="btn btn-perigo">Descartar</button>
                </form>
            </div>
        @elseif (in_array($post->status, [\App\Models\Post::APPROVED, \App\Models\Post::REJECTED, \App\Models\Post::REVISING], true))
            <div class="acoes nota">
                <span>
                    @switch($post->status)
                        @case(\App\Models\Post::APPROVED) Vai ser publicado sozinho à hora marcada. @break
                        @case(\App\Models\Post::REJECTED) Não vai ser publicado. @break
                        @default A nova versão está a ser preparada e volta à fila sozinha.
                    @endswitch
                </span>
                @if ($post->isEditable())
                    <a class="btn btn-link" href="{{ route('posts.edit', $post) }}">Editar</a>
                @endif
                <form method="POST" action="{{ route('posts.undo', $post) }}">
                    @csrf
                    <button type="submit" class="btn btn-link">Desfazer</button>
                </form>
            </div>
        @endif

        @if ($post->reviews->isNotEmpty())
            <details class="historico">
                <summary>Histórico ({{ $post->reviews->count() }})</summary>
                <ul>
                    @foreach ($post->reviews->take(8) as $r)
                        <li>
                            {{ $r->created_at->locale('pt_PT')->translatedFormat('j M, H:i') }} —
                            {{ $r->user?->name ?? 'Sistema' }} {{ \App\Models\PostReview::LABELS[$r->action] ?? $r->action }}@if ($r->comment): “{{ $r->comment }}”@endif
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
</article>
