@extends('layout')

@section('title', 'Editar: '.$post->title)

@section('body')
@include('painel.topo')

@php($d = $post->design ?? [])
<div class="pagina-editor">
    <div class="editor-cabeca">
        <a href="{{ route('painel') }}" class="voltar">← Voltar ao painel</a>
        <div class="cabeca">
            <h1>{{ $post->title }}</h1>
            <span class="estado estado-{{ $post->status }}">{{ $post->statusLabel() }}</span>
        </div>
    </div>

    @if (session('ok'))<div class="aviso" role="status">{{ session('ok') }}</div>@endif
    @if (session('erro'))<div class="aviso erro" role="alert">{{ session('erro') }}</div>@endif
    @foreach (session('avisos', []) as $a)<div class="aviso amarelo">⚠ {{ $a }}</div>@endforeach
    @if ($errors->any())
        <div class="aviso erro" role="alert">@foreach ($errors->all() as $e){{ $e }}<br>@endforeach</div>
    @endif
    @if ($post->status === \App\Models\Post::REVISING && $post->change_request)
        <div class="pedido-alteracao"><strong>Alteração pedida:</strong> {{ $post->change_request }}</div>
    @endif

    @if (! $post->isEditable())
        <div class="aviso">Este post já foi {{ $post->status === \App\Models\Post::PUBLISHED ? 'publicado' : 'aprovado' }}.
            Para o alterar, use “Desfazer” no painel primeiro.</div>
        @if ($post->image_path)<img class="imagem-final" src="{{ asset($post->image_path) }}" alt="{{ $post->image_alt }}">@endif
    @else
    <div class="editor">
        <form id="form-post" method="POST" action="{{ route('posts.update', $post) }}" class="editor-campos" autocomplete="off">
            @csrf
            <section class="caixa">
                <h2>Publicação</h2>
                <div class="dupla">
                    <label>Nome do post <small>(só no painel)</small>
                        <input name="title" required maxlength="150" value="{{ old('title', $post->title) }}">
                    </label>
                    <label>Dia e hora de publicação
                        <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $post->scheduled_at?->format('Y-m-d\TH:i')) }}">
                    </label>
                </div>
                <div class="redes">
                    <span class="rotulo">Redes</span>
                    @foreach (\App\Models\Post::NETWORKS as $net)
                        <label class="check"><input type="checkbox" name="networks[]" value="{{ $net }}"
                            @checked(in_array($net, old('networks', $post->networks ?? []), true))> {{ $net }}</label>
                    @endforeach
                </div>
                <label>Legenda <small id="conta-legenda"></small>
                    <textarea name="caption" id="legenda" rows="7" maxlength="2200"
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
                    <label>Título na imagem
                        <textarea data-campo="titulo" rows="2"></textarea>
                        <small>Enter muda de linha. <code>*palavra*</code> fica a verde.</small>
                    </label>
                    <label>Texto <textarea data-campo="texto" rows="2"></textarea></label>

                    <label data-modelos="dica produto">Pontos
                        <textarea id="f-pontos" rows="3"></textarea>
                        <small>Um por linha. Na dica, <code>3 | cópias dos dados</code> faz um cartão com o 3 em grande.</small>
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

                    @foreach ([
                        'fundo' => ['Fundo', 'imagem da IA de imagens; sem fundo usa o verde da marca', ''],
                        'produto' => ['Foto oficial do produto', 'substitui o ícone', 'produto'],
                        'parceiro' => ['Logótipo oficial do parceiro', 'Supermicro, NVIDIA, Riello…', 'produto'],
                    ] as $campo => [$rotulo, $ajuda, $so])
                        <div class="imagem-campo" data-imagem="{{ $campo }}" @if ($so) data-modelos="{{ $so }}" @endif>
                            <span class="rotulo">{{ $rotulo }} <small>({{ $ajuda }})</small></span>
                            <b class="nome"></b>
                            <label class="btn btn-contorno pequeno">Escolher…<input type="file" accept="image/png,image/jpeg,image/webp" hidden></label>
                            <button type="button" class="btn pequeno tirar">Tirar</button>
                        </div>
                    @endforeach
                @endif
            </section>
        </form>

        <aside class="editor-lado">
            @if ($post->design)
                <div id="avisos-vivo"></div>
                <div class="moldura" id="moldura"><iframe id="previa" title="Pré-visualização do post"></iframe></div>
            @elseif ($post->image_path)
                <img class="imagem-final" src="{{ asset($post->image_path) }}" alt="{{ $post->image_alt }}">
            @endif

            <div class="acoes">
                <button type="submit" form="form-post" class="btn btn-principal" id="btn-guardar">
                    {{ $post->design ? 'Guardar e gerar imagem' : 'Guardar' }}
                </button>
                @if ($post->status !== \App\Models\Post::PENDING)
                    <form method="POST" action="{{ route('posts.submit', $post) }}">
                        @csrf
                        <button type="submit" class="btn btn-contorno" @disabled(! $post->image_path)
                                title="{{ $post->image_path ? '' : 'Gere a imagem primeiro' }}">Enviar para aprovação</button>
                    </form>
                @else
                    <span class="origem">Está na fila para aprovar; as alterações ficam no histórico.</span>
                @endif
            </div>
            @if ($post->design && $post->image_path)
                <p class="origem">Última imagem gerada: <a href="{{ asset($post->image_path) }}" target="_blank" rel="noopener">abrir</a></p>
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
    csrf: @json(csrf_token()),
};
</script>
<script src="{{ asset('js/editor.js') }}?v={{ filemtime(public_path('js/editor.js')) }}"></script>
@endif
@endsection
