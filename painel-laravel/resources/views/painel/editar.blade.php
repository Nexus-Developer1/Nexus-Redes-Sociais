@extends('layout')

@section('title', 'Editar: '.$post->title)
@section('body_class', $post->isEditable() ? 'ecra-editor' : '')

@section('body')
@php($passo = 0)

<div class="pagina-editor">
    {{-- Barra fixa: título, estado e botões sempre visíveis; o resto fica no menu "⋯" --}}
    <div class="editor-barra">
        <h1 title="{{ $post->title }}">{{ $post->title }}</h1>
        <span class="estado estado-{{ $post->status }}">{{ $post->statusLabel() }}</span>
        <div class="barra-acoes">
            @if ($post->isEditable())
                <button type="submit" form="form-post" class="btn btn-principal" id="btn-guardar">
                    {{ $post->design ? 'Guardar e gerar imagem' : 'Guardar' }}
                </button>
                @if ($post->status !== \App\Models\Post::PENDING)
                    <form method="POST" action="{{ route('posts.submit', $post) }}">
                        @csrf
                        <button type="submit" class="btn btn-contorno" @disabled(! $post->image_path)
                                title="{{ $post->image_path ? 'Põe o post na fila para aprovar' : 'Gere a imagem primeiro' }}">Enviar para aprovação</button>
                    </form>
                @endif
            @endif
            <details class="mais-opcoes">
                <summary title="Mais opções" aria-label="Mais opções">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
                </summary>
                <div class="mais-painel">
                    @if ($post->image_path)
                        <a class="mais-item" href="{{ asset($post->image_path) }}" target="_blank" rel="noopener">Abrir a última imagem gerada ↗</a>
                        @if ($post->enviar_parceiros && ! $post->parceiros_enviado_em)
                            <form method="POST" action="{{ route('posts.partnersTest', $post) }}" data-a-enviar="A enviar o teste…">
                                @csrf
                                <button type="submit" class="mais-item">Enviar-me um teste (como os parceiros o recebem)</button>
                            </form>
                        @endif
                    @endif
                    @include('painel.eliminar')
                </div>
            </details>
        </div>
    </div>

    @if (! $post->isEditable())
        <div class="editor-bloqueado">
            @if (session('erro'))<div class="aviso erro" role="alert">{{ session('erro') }}</div>@endif
            <div class="aviso">Este post já foi {{ $post->status === \App\Models\Post::PUBLISHED ? 'publicado' : 'aprovado' }}.
                Para o alterar, use “Desfazer” no painel primeiro.</div>
            @foreach ($post->imagens() as $i => $img)
                <img class="imagem-final" src="{{ asset($img) }}" alt="{{ $i ? 'Imagem '.($i + 1) : $post->image_alt }}">
            @endforeach
        </div>
    @else
    <div class="editor">
        <form id="form-post" data-avisar-saida method="POST" action="{{ route('posts.update', $post) }}" class="editor-campos" autocomplete="off">
            @csrf

            {{-- Mensagens --}}
            @if (session('ok'))<div class="aviso" role="status">{{ session('ok') }}</div>@endif
            @if (session('erro'))<div class="aviso erro" role="alert">{{ session('erro') }}</div>@endif
            @foreach (session('avisos', []) as $a)<div class="aviso amarelo">⚠ {{ $a }}</div>@endforeach
            @if ($errors->any())
                <div class="aviso erro" role="alert">@foreach ($errors->all() as $e){{ $e }}<br>@endforeach</div>
            @endif
            @if ($post->status === \App\Models\Post::REVISING && $post->change_request)
                <div class="pedido-alteracao"><strong>Alteração pedida:</strong> {{ $post->change_request }}</div>
            @endif
            @if ($post->status === \App\Models\Post::PENDING)
                <div class="aviso">Este post está na fila para aprovar. As alterações ficam registadas no histórico.</div>
            @endif

            @if (! $post->design)
                <section class="bloco">
                    <h2><span class="passo">{{ ++$passo }}</span> Imagem</h2>
                    <p class="origem">Este post ainda não usa um modelo{{ $post->image_path ? ' (a imagem atual foi feita à parte)' : '' }}.
                        Escolha um para fazer a imagem aqui:</p>
                    <div class="dupla">
                        <select name="modelo" aria-label="Modelo">
                            @foreach ($modelos as $m)<option value="{{ $m }}">{{ \App\Services\PostRenderer::NOMES[$m] ?? ucfirst($m) }}</option>@endforeach
                        </select>
                        <button type="submit" class="btn btn-contorno" formaction="{{ route('posts.template', $post) }}" formnovalidate>Usar este modelo</button>
                    </div>
                </section>
            @else
                <input type="hidden" name="design" id="design-json">
                <div id="design" class="editor-grupo">
                    {{-- 1. O que está escrito na imagem --}}
                    <section class="bloco">
                        <h2><span class="passo">{{ ++$passo }}</span> Texto da imagem</h2>
                        <div class="dupla">
                            <label>Tipo
                                <select data-campo="modelo" id="f-modelo">
                                    @foreach ($modelos as $m)<option value="{{ $m }}">{{ \App\Services\PostRenderer::NOMES[$m] ?? ucfirst($m) }}</option>@endforeach
                                </select>
                            </label>
                            <label>Formato
                                <select data-campo="formato" id="f-formato">
                                    <option value="4:5">Feed 4:5 (recomendado)</option>
                                    <option value="1:1">Quadrado 1:1</option>
                                    <option value="9:16">Stories 9:16</option>
                                </select>
                            </label>
                        </div>

                        <label data-modelos="numero">Número <small>só o algarismo, ex.: 5</small>
                            <input data-campo="numero" maxlength="4" placeholder="5">
                        </label>
                        <label>Título <small>Enter muda de linha · <code>*palavra*</code> fica a verde</small>
                            <textarea data-campo="titulo" rows="2"></textarea>
                        </label>
                        <label>Texto <small>uma frase curta por baixo do título</small>
                            <textarea data-campo="texto" rows="2"></textarea>
                        </label>

                        <div data-modelos="aviso" class="dupla">
                            <label>Data <input data-campo="data" placeholder="Ex.: Terça-feira, 1 de dezembro"></label>
                            <label>Hora <input data-campo="hora" placeholder="opcional"></label>
                        </div>
                        <div data-modelos="evento" class="quatro">
                            <label>Dia <input data-evento="dia" placeholder="12"></label>
                            <label>Mês <input data-evento="mes" placeholder="Nov"></label>
                            <label>Hora <input data-evento="hora" placeholder="14h30 – 17h00"></label>
                            <label>Local <input data-evento="local" placeholder="Porto"></label>
                        </div>

                        <label data-modelos="dica produto">Pontos <small>um por linha · na dica, <code>3 | cópias dos dados</code> faz um cartão</small>
                            <textarea id="f-pontos" rows="3"></textarea>
                        </label>
                        {{-- Carrossel: esta imagem é a capa; depois um slide por ponto e no fim os contactos --}}
                        <div class="carrossel-campo" data-modelos="dica numero produto frase montra">
                            <label class="check"><input type="checkbox" id="f-carrossel"> <b>Carrossel</b>
                                <small>várias imagens no mesmo post: esta é a capa, depois um slide por ponto e no fim os contactos</small></label>
                            <div id="slides" hidden>
                                <ol class="slides-lista" id="slides-lista"></ol>
                                <button type="button" class="btn btn-contorno pequeno" id="slide-mais">+ Slide</button>
                                <label>Último slide (contactos) <small>título · Enter muda de linha · <code>*palavra*</code> fica a verde</small>
                                    <textarea data-campo="fecho" rows="2" maxlength="80" placeholder="Fale com a&#10;*NEXUS*"></textarea>
                                </label>
                            </div>
                        </div>

                        {{-- Pormenores: raramente se mudam --}}
                        <details class="mais-campos" data-modelos="dica produto aviso evento montra">
                            <summary>Mais opções da imagem <small>ícone, botão, etiqueta e rodapé</small></summary>
                            <div class="mais-campos-corpo">
                                <div class="dupla">
                                    <label data-modelos="dica produto evento montra">Ícone
                                        <select data-campo="icone">
                                            <option value="">(nenhum)</option>
                                            @foreach ($icones as $i)<option value="{{ $i }}">{{ $i }}</option>@endforeach
                                        </select>
                                    </label>
                                    <label data-modelos="dica produto evento">Botão
                                        <input data-campo="cta" placeholder="ex.: Peça a sua proposta">
                                    </label>
                                </div>
                                <div class="dupla">
                                    <label>Etiqueta <input data-campo="etiqueta" placeholder="(a do tipo de post)"></label>
                                    <label>Rodapé <input data-campo="rodape" placeholder="tema do post"></label>
                                </div>
                            </div>
                        </details>
                    </section>

                    {{-- 2. Fundo e imagens oficiais --}}
                    <section class="bloco">
                        <h2><span class="passo">{{ ++$passo }}</span> Fundo</h2>
                        <div class="imagem-campo" data-imagem="foto">
                            <span class="rotulo">Imagem de fundo <small>opcional · JPG ou PNG até 20 MB · sem imagem fica o verde da marca</small></span>
                            <b class="nome"></b>
                            <div class="imagem-botoes">
                                <label class="btn btn-contorno pequeno">Carregar…<input type="file" accept="image/png,image/jpeg,image/webp" hidden></label>
                                <button type="button" class="btn btn-contorno pequeno da-biblioteca" data-campo="foto">Da biblioteca…</button>
                                <button type="button" class="btn pequeno tirar">Tirar</button>
                            </div>
                        </div>
                        @if ($pexels)
                            <details class="procurar-imagem">
                                <summary class="btn btn-contorno pequeno">Procurar imagem grátis ({{ $banco }})…</summary>
                                <div class="procura-img">
                                    <div class="procura-barra">
                                        <input type="search" id="pexels-q" placeholder="Ex.: sala de servidores, equipa no escritório, portátil">
                                        <button type="button" class="btn btn-principal pequeno" id="pexels-ir">Procurar</button>
                                    </div>
                                    <div class="procura-grelha" id="pexels-grelha"></div>
                                    <small>Fotos gratuitas do {{ $banco }}: uso comercial permitido. Clique numa para a usar.</small>
                                </div>
                            </details>
                        @endif
                        <div class="dupla">
                            <label>Enquadramento
                                <select data-campo="enquadramento">
                                    <option value="centro">Centro</option>
                                    <option value="topo">Mostrar mais o topo</option>
                                    <option value="baixo">Mostrar mais o fundo</option>
                                </select>
                            </label>
                            <label>Tom verde
                                <select data-campo="tom">
                                    <option value="suave">Suave (recomendado)</option>
                                    <option value="forte">Forte (imagem toda verde)</option>
                                    <option value="nenhum">Nenhum (imagem natural)</option>
                                </select>
                            </label>
                        </div>

                        <div class="imagens" data-modelos="produto montra">
                            @foreach ([
                                'produto' => ['Foto do produto', 'oficial, de preferência PNG sem fundo; substitui o ícone', 'produto montra'],
                                'parceiro' => ['Logótipo do parceiro', 'oficial: Supermicro, NVIDIA, Riello…', 'produto montra'],
                            ] as $campo => [$rotulo, $ajuda, $so])
                                <div class="imagem-campo" data-imagem="{{ $campo }}" @if ($so) data-modelos="{{ $so }}" @endif>
                                    <span class="rotulo">{{ $rotulo }} <small>{{ $ajuda }}</small></span>
                                    <b class="nome"></b>
                                    <div class="imagem-botoes">
                                        <label class="btn btn-contorno pequeno">Escolher…<input type="file" accept="image/png,image/jpeg,image/webp" hidden></label>
                                        <button type="button" class="btn btn-contorno pequeno da-biblioteca" data-campo="{{ $campo }}">Da biblioteca…</button>
                                        <button type="button" class="btn pequeno tirar">Tirar</button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                </div>
            @endif

            {{-- 3. Legenda: a IA escreve ou melhora; só fica guardada com "Guardar" --}}
            <section class="bloco campo-legenda">
                <div class="bloco-topo">
                    <h2 id="h-legenda"><span class="passo">{{ ++$passo }}</span> Legenda</h2>
                    <small id="conta-legenda" class="conta"></small>
                </div>
                @if ($ia)
                    <div class="ia-legenda" role="group" aria-label="IA na legenda">
                        <button type="button" class="chip" data-acao="escrever">✨ Escrever com IA</button>
                        <details class="mais-opcoes ia-menu">
                            <summary class="chip">Melhorar com IA ▾</summary>
                            <div class="mais-painel">
                                <button type="button" class="mais-item" data-acao="corrigir">Corrigir o português</button>
                                <button type="button" class="mais-item" data-acao="curta">Mais curta</button>
                                <button type="button" class="mais-item" data-acao="profissional">Mais profissional</button>
                                <button type="button" class="mais-item" data-acao="proxima">Mais próxima</button>
                                <button type="button" class="mais-item" data-acao="hashtags">Trocar as hashtags</button>
                            </div>
                        </details>
                        <button type="button" class="chip desfazer" id="ia-desfazer" hidden>↶ Desfazer</button>
                        <span class="ia-estado" id="ia-estado" aria-live="polite"></span>
                    </div>
                @endif
                <textarea name="caption" id="legenda" rows="10" maxlength="2200" aria-labelledby="h-legenda"
                          placeholder="Texto que acompanha a imagem no Instagram e no Facebook, com as hashtags no fim.">{{ old('caption', $post->caption) }}</textarea>
            </section>

            {{-- 4. Quando e onde sai --}}
            <section class="bloco">
                <h2><span class="passo">{{ ++$passo }}</span> Publicação</h2>
                <div class="dupla">
                    <label>Dia e hora
                        <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $post->scheduled_at?->format('Y-m-d\TH:i')) }}">
                    </label>
                    <label>Nome do post <small>só aparece no painel</small>
                        <input name="title" required maxlength="150" value="{{ old('title', $post->title) }}">
                    </label>
                </div>
                <div class="redes">
                    <span class="rotulo">Redes</span>
                    @foreach (\App\Models\Post::NETWORKS as $net)
                        <label class="check"><input type="checkbox" name="networks[]" value="{{ $net }}"
                            @checked(in_array($net, old('networks', $post->networks ?? []), true))> {{ $net }}@if ($net === 'Stories')
                            <small title="Stories do Instagram: o servidor faz sozinho a versão 9:16 do post">(9:16)</small>@endif</label>
                    @endforeach
                </div>
                {{-- Email aos parceiros: à hora da publicação, só depois de aprovado --}}
                @if ($post->parceiros_enviado_em)
                    <p class="origem">✉ Enviado por email aos parceiros {{ $post->parceiros_enviado_em->locale('pt_PT')->translatedFormat('j \d\e F, H:i') }}.</p>
                @else
                    <input type="hidden" name="enviar_parceiros" value="0">
                    <label class="check"><input type="checkbox" name="enviar_parceiros" value="1" @checked(old('enviar_parceiros', $post->enviar_parceiros))>
                        Email aos parceiros
                        <small>({{ \App\Models\Parceiro::quantosAtivos() }} na <a href="{{ route('parceiros') }}">lista</a> · sai à hora da publicação, depois de aprovado · desligue só como exceção)</small></label>
                @endif
            </section>
        </form>

        <aside class="editor-lado">
            {{-- Como fica no feed do Instagram: a imagem da última versão gerada e a legenda que está a ser escrita --}}
            <div class="lado-topo">
                @if ($post->design)
                    {{-- Ver a versão do feed ou a dos Stories (9:16), que o servidor gera ao guardar --}}
                    <div class="vista-previa" id="vista-previa" role="group" aria-label="Pré-visualização" hidden>
                        <button type="button" class="ativo" data-vista="feed">Feed</button>
                        <button type="button" data-vista="stories">Stories</button>
                    </div>
                @endif
                <button type="button" class="btn pequeno ver-ig-editor" data-ig-abrir="{{ $post->id }}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="3"/><path d="M11 18h2"/></svg>
                    Ver no Instagram
                </button>
                @if ($post->enviar_parceiros)
                    {{-- A outra vertente: o email aos parceiros, tal como sai (com o que está guardado) --}}
                    <a class="btn pequeno" href="{{ route('posts.partnersPreview', $post) }}" target="_blank" rel="noopener">✉ Ver o email</a>
                @endif
            </div>
            @include('painel.instagram', ['fonteLegenda' => '#legenda'])
            @if ($post->design)
                <div class="slides-nav" id="slides-nav" hidden>
                    <button type="button" class="btn pequeno" id="slide-ant" aria-label="Imagem anterior">‹</button>
                    <span id="slide-qual" aria-live="polite"></span>
                    <button type="button" class="btn pequeno" id="slide-seg" aria-label="Imagem seguinte">›</button>
                </div>
                <div id="avisos-vivo"></div>
                <div class="area-previa" id="area-previa">
                    <div class="moldura" id="moldura"><iframe id="previa" title="Pré-visualização do post"></iframe></div>
                </div>
            @elseif ($post->image_path)
                <div class="area-previa"><img class="imagem-final" src="{{ asset($post->image_path) }}" alt="{{ $post->image_alt }}"></div>
            @else
                <div class="area-previa vazia">Sem imagem</div>
            @endif
        </aside>
    </div>
    @endif
</div>

@if ($post->isEditable() && $post->design)
{{-- Escolher uma imagem da biblioteca da NEXUS --}}
<dialog id="dlg-biblioteca" aria-labelledby="dlg-titulo">
    <div class="dlg-cabeca">
        <h2 id="dlg-titulo">Biblioteca</h2>
        <button type="button" class="btn pequeno" id="dlg-fechar">Fechar</button>
    </div>
    <div class="dlg-grelha" id="dlg-grelha"></div>
</dialog>
@endif

@if ($post->isEditable())
<script>
window.EDITOR = {
    design: @json($post->design),
    pasta: @json($pastaUrl),
    modelos: @json(asset('modelos').'/'),
    upload: @json(route('posts.upload', $post)),
    legendaIa: @json($ia ? route('posts.captionAi', $post) : null),
    biblioteca: { lista: @json(route('biblioteca.lista')), usar: @json(route('posts.biblioteca', $post)), pagina: @json(route('biblioteca')) },
    pexels: @json($pexels ? ['procurar' => route('images.search'), 'usar' => route('posts.stock', $post)] : null),
    csrf: @json(csrf_token()),
    feriados: @json(\App\Support\AvisoDatas::feriados()),
    avisoExemplo: @json(array_intersect_key(\App\Services\PostRenderer::exemplo('aviso'), ['data' => 1, 'texto' => 1])),
};
</script>
<script src="{{ asset('js/editor.js') }}?v={{ filemtime(public_path('js/editor.js')) }}"></script>
@endif
@endsection
