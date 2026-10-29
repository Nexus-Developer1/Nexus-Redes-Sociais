@extends('layout')

@section('title', 'Editar: '.$post->title)
@section('body_class', $post->isEditable() ? 'ecra-editor' : '')

@section('body')
@include('painel.topo')

<div class="pagina-editor">
    {{-- Barra fixa: título, estado e botões sempre visíveis --}}
    <div class="editor-barra">
        <a href="{{ route('painel') }}" class="voltar" title="Voltar ao painel"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg> Painel</a>
        <h1>{{ $post->title }}</h1>
        <span class="estado estado-{{ $post->status }}">{{ $post->statusLabel() }}</span>
        @if ($post->isEditable())
            <div class="barra-acoes">
                @if ($post->design && $post->image_path)
                    <a href="{{ asset($post->image_path) }}" target="_blank" rel="noopener" class="btn btn-link">Ver última imagem</a>
                @endif
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
            </div>
        @endif
    </div>

    @if (! $post->isEditable())
        <div class="editor-bloqueado">
            <div class="aviso">Este post já foi {{ $post->status === \App\Models\Post::PUBLISHED ? 'publicado' : 'aprovado' }}.
                Para o alterar, use “Desfazer” no painel primeiro.</div>
            @if ($post->image_path)<img class="imagem-final" src="{{ asset($post->image_path) }}" alt="{{ $post->image_alt }}">@endif
        </div>
    @else
    <div class="editor">
        <form id="form-post" method="POST" action="{{ route('posts.update', $post) }}" class="editor-campos" autocomplete="off">
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

            <div class="colunas">
                <section class="caixa">
                    <h2>Publicação</h2>
                    <label>Nome do post <small>(só aparece no painel)</small>
                        <input name="title" required maxlength="150" value="{{ old('title', $post->title) }}">
                    </label>
                    <label>Dia e hora de publicação
                        <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $post->scheduled_at?->format('Y-m-d\TH:i')) }}">
                    </label>
                    <div class="redes">
                        <span class="rotulo">Redes</span>
                        @foreach (\App\Models\Post::NETWORKS as $net)
                            <label class="check"><input type="checkbox" name="networks[]" value="{{ $net }}"
                                @checked(in_array($net, old('networks', $post->networks ?? []), true))> {{ $net }}</label>
                        @endforeach
                    </div>
                    <label class="cresce">Legenda <small id="conta-legenda"></small>
                        <textarea name="caption" id="legenda" rows="10" maxlength="2200"
                                  placeholder="Texto que acompanha a imagem no Instagram/Facebook, com hashtags no fim.">{{ old('caption', $post->caption) }}</textarea>
                    </label>
                </section>

                <section class="caixa" id="design">
                    <h2>Imagem</h2>
                    @if (! $post->design)
                        <p class="origem">Este post ainda não usa um modelo{{ $post->image_path ? ' (a imagem atual foi feita à parte)' : '' }}.
                            Escolha um para fazer a imagem aqui:</p>
                        <div class="dupla">
                            <select name="modelo" aria-label="Modelo">
                                @foreach ($modelos as $m)<option value="{{ $m }}">{{ ucfirst($m) }}</option>@endforeach
                            </select>
                            <button type="submit" class="btn btn-contorno" formaction="{{ route('posts.template', $post) }}" formnovalidate>Usar este modelo</button>
                        </div>
                    @else
                        <input type="hidden" name="design" id="design-json">
                        <div class="dupla">
                            <label>Tipo
                                <select data-campo="modelo" id="f-modelo">
                                    @foreach ($modelos as $m)<option value="{{ $m }}">{{ ucfirst($m) }}</option>@endforeach
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

                        {{-- Imagem de fundo (qualquer imagem), em qualquer tipo de post --}}
                        <div class="foto-destaque">
                            <div class="imagem-campo" data-imagem="foto">
                                <span class="rotulo">Imagem de fundo <small>(opcional: qualquer imagem ou foto, JPG ou PNG até 20 MB; sem imagem usa o verde da marca)</small></span>
                                <b class="nome"></b>
                                <label class="btn btn-principal pequeno">Carregar imagem…<input type="file" accept="image/png,image/jpeg,image/webp" hidden></label>
                                <button type="button" class="btn pequeno tirar">Tirar</button>
                            </div>
                            @if ($pexels)
                                <details class="procurar-imagem">
                                    <summary class="btn btn-contorno pequeno">Procurar imagem grátis ({{ $banco }})…</summary>
                                    <div class="procura">
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
                                <label>Enquadramento <small>que parte da imagem mostrar</small>
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
                        </div>

                        <label>Título na imagem <small>Enter muda de linha · <code>*palavra*</code> fica a verde</small>
                            <textarea data-campo="titulo" rows="2"></textarea>
                        </label>
                        <label>Texto <textarea data-campo="texto" rows="2"></textarea></label>

                        <label data-modelos="dica produto">Pontos <small>um por linha · na dica, <code>3 | cópias dos dados</code> faz um cartão</small>
                            <textarea id="f-pontos" rows="3"></textarea>
                        </label>
                        <div data-modelos="aviso" class="dupla">
                            <label>Data <input data-campo="data" placeholder="Sexta-feira, 23 de outubro"></label>
                            <label>Hora <input data-campo="hora" placeholder="opcional"></label>
                        </div>
                        <div data-modelos="evento" class="quatro">
                            <label>Dia <input data-evento="dia" placeholder="12"></label>
                            <label>Mês <input data-evento="mes" placeholder="Nov"></label>
                            <label>Hora <input data-evento="hora" placeholder="14h30 – 17h00"></label>
                            <label>Local <input data-evento="local" placeholder="Porto"></label>
                        </div>
                        <div class="dupla">
                            <label data-modelos="dica produto evento">Ícone
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

                        <div class="imagens" data-modelos="produto">
                            @foreach ([
                                'produto' => ['Foto do produto', 'oficial; substitui o ícone', 'produto'],
                                'parceiro' => ['Logótipo do parceiro', 'oficial: Supermicro, NVIDIA, Riello…', 'produto'],
                            ] as $campo => [$rotulo, $ajuda, $so])
                                <div class="imagem-campo" data-imagem="{{ $campo }}" @if ($so) data-modelos="{{ $so }}" @endif>
                                    <span class="rotulo">{{ $rotulo }} <small>({{ $ajuda }})</small></span>
                                    <b class="nome"></b>
                                    <label class="btn btn-contorno pequeno">Escolher…<input type="file" accept="image/png,image/jpeg,image/webp" hidden></label>
                                    <button type="button" class="btn pequeno tirar">Tirar</button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>
        </form>

        <aside class="editor-lado">
            @if ($post->design)
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

@if ($post->isEditable())
<script>
window.EDITOR = {
    design: @json($post->design),
    pasta: @json($pastaUrl),
    modelos: @json(asset('modelos').'/'),
    upload: @json(route('posts.upload', $post)),
    pexels: @json($pexels ? ['procurar' => route('images.search'), 'usar' => route('posts.stock', $post)] : null),
    csrf: @json(csrf_token()),
};
</script>
<script src="{{ asset('js/editor.js') }}?v={{ filemtime(public_path('js/editor.js')) }}"></script>
@endif
@endsection
