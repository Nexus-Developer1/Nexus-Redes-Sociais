@php
    $iaLigada = app(\App\Services\Ia\ClienteIa::class)->configurado();
    // O controlador já pergunta isto de uma vez para todos os cartões (withExists); a consulta é só o plano B.
    $iaAFazer = $post->ia_a_fazer ?? $post->pedidos()->whereIn('status', ['new', 'in_progress'])->exists();
    $iaFalhou = $post->ia_falhou ?? $post->pedidos()->where('status', 'failed')->exists();
    // A IA está a trabalhar neste post? A página atualiza-se sozinha quando acabar (layout.blade.php).
    $esperaIa = null;
    if ($iaLigada) {
        if ($post->status === \App\Models\Post::CREATING && $iaAFazer) {
            $esperaIa = 'post';      // "A IA faz este post"
        } elseif ($post->aRefazer() || ($post->status === \App\Models\Post::REVISING && $post->design && $post->change_request)) {
            $esperaIa = 'post';      // "Pedir alteração"
        } elseif ($post->status === \App\Models\Post::IDEA && $post->change_request) {
            $esperaIa = 'tema';      // "Trocar tema"
        }
    }
    $eIdeia = in_array($post->status, [\App\Models\Post::IDEA, \App\Models\Post::CREATING], true) && ! $post->aRefazer();
    $tipo = $post->isAviso() ? 'aviso' : (preg_match('/^Tipo: (\w+)/u', (string) $post->source, $m) ? $m[1] : null);
    $atrasado = $post->status === \App\Models\Post::PENDING && $post->atrasado();
    $metaLigada = app(\App\Services\MetaPublisher::class)->configured();
@endphp
<article class="post {{ $eIdeia && ! $post->image_path ? 'post--ideia' : '' }}" id="post-{{ $post->id }}"
         @if ($esperaIa) data-espera="{{ $esperaIa }}" data-id="{{ $post->id }}" data-estado="{{ $post->status }}" @endif>
    @php($temIg = $post->image_path && array_intersect(['Instagram', 'Stories'], $post->networks ?? []))
    @php($outraFoto = $post->isEditable() && $post->design && ! $post->aRefazer() && app(\App\Services\BancoFotos::class)->configurado())
    <div class="post-imagem">
        @if ($post->imagens_carrossel)
            {{-- Carrossel: as imagens passam para o lado, como no Instagram --}}
            @php($totalImagens = count($post->imagens_carrossel))
            <div class="carrossel" data-carrossel>
            <div class="carrossel-galeria" tabindex="0" aria-label="Carrossel com {{ $totalImagens }} imagens: use as setas ou deslize para o lado">
                @foreach ($post->imagens_carrossel as $i => $img)
                    <a href="{{ asset($img) }}" target="_blank" rel="noopener" title="Abrir a imagem {{ $i + 1 }} em tamanho real">
                        <img src="{{ \App\Support\Miniatura::url($img) }}" alt="{{ $i ? 'Imagem '.($i + 1).' de '.$totalImagens : ($post->image_alt ?? $post->title) }}" loading="lazy" decoding="async">
                    </a>
                @endforeach
            </div>
            <button type="button" class="carrossel-seta ant" aria-label="Imagem anterior" hidden>‹</button>
            <button type="button" class="carrossel-seta seg" aria-label="Imagem seguinte">›</button>
            <span class="carrossel-conta">Carrossel · 1 / {{ $totalImagens }}</span>
            </div>
        @elseif ($temIg)
            {{-- Clicar na imagem mostra o post como fica no Instagram (imagem grande + legenda) --}}
            <button type="button" class="post-foto" data-ig-abrir="{{ $post->id }}" title="Ver como fica no Instagram">
                <img src="{{ \App\Support\Miniatura::url($post->image_path) }}" alt="{{ $post->image_alt ?? $post->title }}" loading="lazy" decoding="async">
                <span class="post-foto-dica" aria-hidden="true">Ver no Instagram</span>
            </button>
        @elseif ($post->image_path)
            <a href="{{ asset($post->image_path) }}" target="_blank" rel="noopener" title="Abrir a imagem em tamanho real">
                <img src="{{ \App\Support\Miniatura::url($post->image_path) }}" alt="{{ $post->image_alt ?? $post->title }}" loading="lazy" decoding="async">
            </a>
        @elseif ($eIdeia)
            {{-- Ideia: ainda não há imagem; mostra só o tipo de post --}}
            <div class="sem-imagem sem-imagem--ideia" aria-hidden="true">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.4 1 1.1 1 1.8V16h5v-.3c0-.7.4-1.4 1-1.8A6 6 0 0 0 12 3z"/></svg>
                <span>{{ $tipo ? ucfirst($tipo) : 'Ideia' }}</span>
            </div>
        @else
            <div class="sem-imagem">Sem imagem</div>
        @endif
    </div>

    <div class="corpo">
        <div class="cabeca">
            <h2>{{ $post->title }}</h2>
            {{-- Tudo o que é secundário fica aqui: pré-visualizações, outra foto, origem e histórico --}}
            @php($totalHistorico = $post->reviews_count ?? $post->reviews->count())
            @php($origem = $post->source && ! ($eIdeia && $tipo && preg_match('/^Tipo: \w+\.?\s*$/u', $post->source)) ? $post->source : null)
            @if ($temIg || $post->image_path || $outraFoto || $origem || $totalHistorico || $post->status !== \App\Models\Post::PUBLISHED)
                <details class="mais-opcoes">
                    <summary title="Mais opções" aria-label="Mais opções para “{{ $post->title }}”">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
                    </summary>
                    <div class="mais-painel">
                        @if ($temIg)
                            <button type="button" class="mais-item" data-ig-abrir="{{ $post->id }}">Ver como fica no Instagram</button>
                        @endif
                        @if (in_array('Stories', $post->networks ?? [], true) && $post->imagemStory())
                            <a class="mais-item" href="{{ asset($post->imagemStory()) }}" target="_blank" rel="noopener">Ver a versão dos Stories (9:16) ↗</a>
                        @endif
                        @if ($post->image_path)
                            <a class="mais-item" href="{{ asset($post->image_path) }}" target="_blank" rel="noopener">Abrir a imagem em tamanho real ↗</a>
                        @endif
                        @if ($outraFoto)
                            {{-- A IA escolhe outra foto do banco de fotos e a imagem é refeita --}}
                            <form method="POST" action="{{ route('posts.otherPhoto', $post) }}" data-a-enviar="A trocar a foto…">
                                @csrf
                                <button type="submit" class="mais-item">↻ Trocar a foto de fundo (a IA escolhe)</button>
                            </form>
                        @endif
                        @if ($origem)
                            <div class="mais-info"><b>Origem</b><p>{{ $origem }}</p></div>
                        @endif
                        @if ($totalHistorico)
                            <div class="mais-info">
                                <b>Histórico ({{ $totalHistorico }})</b>
                                <ul>
                                    @foreach ($post->reviews->take(8) as $r)
                                        <li><span>{{ $r->created_at->locale('pt_PT')->translatedFormat('j M, H:i') }}</span>
                                            {{ $r->user?->name ?? 'Sistema' }} {{ \App\Models\PostReview::LABELS[$r->action] ?? $r->action }}@if ($r->comment): “{{ $r->comment }}”@endif</li>
                                    @endforeach
                                </ul>
                                @if ($totalHistorico > 8)<p class="historico-mais">As 8 entradas mais recentes de {{ $totalHistorico }}.</p>@endif
                            </div>
                        @endif
                        @include('painel.parceiros-menu')
                        @include('painel.eliminar')
                    </div>
                </details>
            @endif
        </div>

        <div class="meta">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="meta-icone"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg>
            @if ($post->isEditable() && ! $post->aRefazer())
                {{-- Clicar na data abre um campo para a mudar logo aqui --}}
                <details class="mudar-data">
                    <summary title="Mudar o dia e a hora de publicação"><span class="quando">{{ $post->whenLabel() }}</span>
                        <svg class="mudar-lapis" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Mudar data"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></summary>
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
            <span class="redes-txt">{{ implode(' · ', $post->networks ?? []) }}</span>
            @if ($post->enviar_parceiros)
                <span class="sep">·</span>
                <span class="redes-txt parceiros-txt" title="Vai também por email aos parceiros, à hora da publicação">✉ {{ $post->parceiros_enviado_em ? 'Enviado aos parceiros' : 'Parceiros' }}</span>
            @endif
            {{-- Estado só quando diz alguma coisa: em cada secção quase todos os posts têm o mesmo --}}
            @if ($atrasado)
                <span class="estado estado-atrasado" title="A hora marcada já passou ou falta menos de uma hora">Atrasado</span>
            @endif
            @if ($post->aRefazer() || in_array($post->status, [\App\Models\Post::CREATING, \App\Models\Post::REVISING], true) || ! empty($mostrarEstado))
                <span class="estado estado-{{ $post->aRefazer() ? 'revising' : $post->status }}">{{ $post->aRefazer() ? 'A IA está a refazer' : $post->statusLabel() }}</span>
            @endif
        </div>

        @if ($post->caption)
            <div class="legenda-post" data-legenda>{{ $post->caption }}</div>
            <button type="button" class="ver-mais" hidden>Ver legenda completa</button>
        @endif

        @if ($post->status === \App\Models\Post::APPROVED && $post->publish_error)
            <div class="pedido-alteracao erro">
                <strong>Não foi publicado.</strong>
                @if ($post->publish_attempts > 0)
                    ({{ $post->publish_attempts }} de {{ \App\Models\Post::MAX_PUBLISH_ATTEMPTS }} tentativas{{ $post->publish_attempts >= \App\Models\Post::MAX_PUBLISH_ATTEMPTS ? '; parou de tentar' : '; vai tentar de novo' }})
                @endif
                {{ $post->publish_error }}
                @if ($post->publish_attempts >= \App\Models\Post::MAX_PUBLISH_ATTEMPTS)
                    <form method="POST" action="{{ route('posts.retryPublish', $post) }}" class="inline-form">
                        @csrf
                        <button type="submit" class="btn pequeno">Tentar publicar de novo</button>
                    </form>
                @endif
            </div>
        @endif

        @if (($post->status === \App\Models\Post::REVISING || $post->aRefazer()) && $post->change_request)
            <div class="pedido-alteracao"><strong>Alteração pedida:</strong> {{ $post->change_request }}</div>
        @endif

        {{-- Dois avisos para o mesmo fecho (ex.: pedidos repetidos): avisa antes de aprovar os dois --}}
        @if (! in_array($post->status, [\App\Models\Post::PUBLISHED, \App\Models\Post::REJECTED], true) && ($repetidos = $post->avisosRepetidos())->isNotEmpty())
            <p class="nota-atraso nota-repetido">
                Pode ser repetido: já há
                @foreach ($repetidos as $r)
                    <a href="{{ route('posts.edit', $r) }}">“{{ $r->title }}”</a> ({{ \Illuminate\Support\Str::lower($r->statusLabel()) }}, {{ $r->whenLabel() }}){{ $loop->last ? '' : ' e' }}
                @endforeach
                para o mesmo fecho. Se for, elimine um deles.
            </p>
        @endif

        {{-- Botões: o principal à esquerda, o perigoso à direita --}}
        @if ($post->status === \App\Models\Post::PENDING)
            @if ($atrasado)
                <p class="nota-atraso">
                    {{ $post->scheduled_at->isPast() ? 'A data marcada já passou.' : 'Falta menos de uma hora para a data marcada.' }}
                    Se aprovar agora, sai {{ $metaLigada ? 'na próxima publicação automática (até 5 minutos)' : 'logo que a Meta estiver ligada' }}. Pode mudar a data em “Mudar data”.
                </p>
            @endif
            <div class="acoes">
                <form method="POST" action="{{ route('posts.approve', $post) }}"
                      @if ($atrasado) data-confirm="A data marcada ({{ $post->whenLabel() }}) já passou ou está quase. O post sai logo. Aprovar na mesma?" @endif>
                    @csrf
                    {{-- a versão que a pessoa está a ver: se o post mudar entretanto, a aprovação é recusada --}}
                    <input type="hidden" name="versao" value="{{ $post->updated_at?->timestamp }}">
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
                            <button type="button" class="btn" data-fechar-details>Cancelar</button>
                        </div>
                    </form>
                </details>
                <a class="btn" href="{{ route('posts.edit', $post) }}">Editar</a>
                <form method="POST" action="{{ route('posts.reject', $post) }}" class="direita"
                      data-confirm="Rejeitar “{{ $post->title }}”? Não vai ser publicado.">
                    @csrf
                    <button type="submit" class="btn btn-perigo">Rejeitar</button>
                </form>
            </div>
        @elseif ($eIdeia)
            @if ($post->change_request)
                <div class="pedido-alteracao"><strong>Pediu outro tema:</strong> {{ $post->change_request }}</div>
            @endif
            @php($diasAntes = (int) config('ia.ideias_dias_antes'))
            @if ($post->status === \App\Models\Post::IDEA && $diasAntes > 0 && $post->scheduled_at?->isFuture() && $iaLigada && ! $iaFalhou)
                @php($quandoIa = $post->scheduled_at->copy()->subDays($diasAntes))
                <p class="nota-auto">✨ A IA faz este post sozinha
                    {{ $quandoIa->isPast() ? 'dentro de 1 a 2 minutos' : 'a '.$quandoIa->locale('pt_PT')->translatedFormat('j \d\e F') }}
                    ({{ $diasAntes }} dias antes da data). Pode trocar o tema ou fazê-lo já.</p>
            @endif
            <div class="acoes">
                @if ($iaAFazer)
                    <span class="ia-a-fazer">⏳ A IA está a fazer este post… <small>(a página atualiza-se sozinha)</small></span>
                @elseif ($iaLigada && $post->status === \App\Models\Post::IDEA && $post->change_request)
                    <span class="ia-a-fazer">⏳ A IA está a escolher outro tema…</span>
                @elseif ($iaLigada)
                    {{-- A IA faz o post a partir desta ideia (tema, notas e data) --}}
                    <form method="POST" action="{{ route('posts.aiMake', $post) }}" data-a-enviar="A pedir…">
                        @csrf
                        <button type="submit" class="btn btn-principal">✨ A IA faz este post</button>
                    </form>
                @endif
                <a class="btn {{ $iaAFazer || $iaLigada ? 'btn-contorno' : 'btn-principal' }}"
                   href="{{ route('posts.edit', $post) }}">{{ $post->design ? 'Continuar a editar' : 'Fazer à mão' }}</a>
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
                                <button type="button" class="btn" data-fechar-details>Cancelar</button>
                            </div>
                        </form>
                    </details>
                @endunless
                <form method="POST" action="{{ route('posts.discard', $post) }}" class="direita"
                      data-confirm="Descartar a ideia “{{ $post->title }}”?">
                    @csrf
                    <button type="submit" class="btn btn-perigo">Descartar</button>
                </form>
            </div>
        @elseif (in_array($post->status, [\App\Models\Post::APPROVED, \App\Models\Post::REJECTED, \App\Models\Post::REVISING], true) || $post->aRefazer())
            <div class="acoes nota">
                <span>
                    @if ($post->status === \App\Models\Post::APPROVED)
                        {{ $metaLigada ? 'Sai sozinho à hora marcada.' : 'Aprovado. Sai sozinho à hora marcada quando a Meta estiver ligada.' }}
                        @if (in_array('LinkedIn', $post->networks, true)) O LinkedIn é publicado à mão. @endif
                    @elseif ($post->status === \App\Models\Post::REJECTED)
                        Não vai ser publicado.
                    @else
                        {{ $esperaIa ? '⏳ A IA está a refazer o post. Esta página atualiza-se sozinha quando estiver pronto.' : 'A nova versão está a ser preparada e volta à fila sozinha.' }}
                    @endif
                </span>
                @if ($post->isEditable() && ! $post->aRefazer())
                    <a class="btn btn-link" href="{{ route('posts.edit', $post) }}">Editar</a>
                @endif
                @if (! $post->aRefazer() && ! $post->publicadoAlgures())
                    <form method="POST" action="{{ route('posts.undo', $post) }}"
                          data-confirm="{{ $post->status === \App\Models\Post::REVISING ? 'Cancelar o pedido de alteração e voltar a pôr o post à espera de aprovação?' : 'Pôr “'.$post->title.'” outra vez à espera de aprovação?' }}">
                        @csrf
                        <button type="submit" class="btn btn-link">Desfazer</button>
                    </form>
                @endif
            </div>
        @endif

    </div>
    @if ($post->image_path && array_intersect(['Instagram', 'Stories'], $post->networks ?? []))
        {{-- fora da coluna da imagem, para as regras de .post-imagem não chegarem à janela --}}
        @include('painel.instagram')
    @endif
</article>
