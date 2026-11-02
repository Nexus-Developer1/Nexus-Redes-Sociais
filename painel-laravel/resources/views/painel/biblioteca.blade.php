@extends('layout')

@section('title', 'Biblioteca')

@php
    // Ícones (SVG fixos) e textos de cada tipo de imagem
    $iconeTipo = [
        'foto' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>',
        'produto' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/></svg>',
        'logo' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12.5V4h8.5l9 9-8.5 8.5z"/><circle cx="7.5" cy="8" r="1.5"/></svg>',
    ];
    $tiposInfo = [
        'foto' => ['Foto', 'Equipa, instalações, escritório, eventos', 'A IA usa-as como fundo dos posts, antes de ir ao banco de fotos grátis.',
            'Ex.: Equipa técnica a montar um servidor no datacenter'],
        'produto' => ['Produto', 'Foto oficial, de preferência PNG sem fundo', 'Para o “Produto em destaque”: produto grande e logótipo do parceiro.',
            'Ex.: Workstation NEXUS Pro G3, torre preta'],
        'logo' => ['Logótipo', 'Parceiros: NVIDIA, Supermicro, Riello…', 'Aparece nos posts de produtos dessa marca (“Powered by”).',
            'Ex.: Logótipo NVIDIA, verde sobre branco'],
    ];
    $tipoEscolhido = old('tipo', $tipo ?: 'foto');
@endphp

@section('body')
@include('painel.topo')

<div class="pagina-biblioteca">
    <div class="editor-barra">
        <a href="{{ route('painel') }}" class="voltar" title="Voltar ao painel"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg> Painel</a>
        <div class="bib-titulo">
            <h1>Biblioteca da NEXUS</h1>
            <span>Fotos, produtos e logótipos oficiais que a IA e o editor usam nos posts</span>
        </div>
        <nav class="filtros" aria-label="Tipo de imagem">
            <a href="{{ route('biblioteca') }}" @class(['ativo' => ! $tipo])>Todas <b>{{ $contagem->sum() }}</b></a>
            @foreach (\App\Models\ImagemBiblioteca::TIPOS as $t => $nome)
                <a href="{{ route('biblioteca', ['tipo' => $t]) }}" @class(['ativo' => $tipo === $t])>{{ $nome }} <b>{{ $contagem[$t] ?? 0 }}</b></a>
            @endforeach
        </nav>
    </div>

    <div class="biblioteca">
        <div class="biblioteca-lado">
            @if (session('ok'))<div class="aviso" role="status">{{ session('ok') }}</div>@endif
            @if ($errors->any())
                <div class="aviso erro" role="alert">@foreach ($errors->all() as $e){{ $e }}<br>@endforeach</div>
            @endif

            <form method="POST" action="{{ route('biblioteca.store') }}" enctype="multipart/form-data" class="caixa bib-adicionar" id="bib-adicionar">
                @csrf
                <h2>Adicionar imagens</h2>

                <fieldset class="bib-tipos">
                    <legend>1. Que tipo de imagem?</legend>
                    @foreach ($tiposInfo as $t => [$nome, $sub])
                        <label class="bib-tipo">
                            <input type="radio" name="tipo" value="{{ $t }}" @checked($tipoEscolhido === $t) data-exemplo="{{ $tiposInfo[$t][3] }}">
                            <span class="bib-tipo-icone">{!! $iconeTipo[$t] !!}</span>
                            <span class="bib-tipo-texto"><b>{{ $nome }}</b><small>{{ $sub }}</small></span>
                        </label>
                    @endforeach
                </fieldset>

                <div class="bib-passo">
                    <span class="bib-rotulo">2. Escolha as imagens <small>(até 20, máx. 20 MB cada)</small></span>
                    <label class="bib-largar" id="bib-largar">
                        <input type="file" name="ficheiros[]" accept="image/png,image/jpeg,image/webp" multiple required id="bib-ficheiros">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
                        <b>Arraste as imagens para aqui</b>
                        <span>ou clique para escolher · PNG, JPG ou WEBP</span>
                    </label>
                    <div class="bib-escolhidas" id="bib-escolhidas" hidden></div>
                </div>

                <label class="bib-passo">
                    <span class="bib-rotulo">3. Descreva-as <small>a IA lê isto para escolher a imagem certa: seja concreto</small></span>
                    <input name="descricao" required maxlength="300" value="{{ old('descricao') }}" id="bib-descricao"
                           placeholder="{{ $tiposInfo[$tipoEscolhido][3] ?? '' }}">
                </label>

                <button type="submit" class="btn btn-principal bib-guardar">Guardar na biblioteca</button>
            </form>
        </div>

        <div class="biblioteca-conteudo">
            @if ($imagens->isEmpty())
                <div class="bib-vazia">
                    <h2>{{ $tipo ? 'Ainda não há '.mb_strtolower(\App\Models\ImagemBiblioteca::TIPOS[$tipo]).'.' : 'A biblioteca ainda está vazia.' }}</h2>
                    <p>Com imagens vossas, os posts ficam mais autênticos: a IA usa-as primeiro, e o editor tem o botão “Da biblioteca…”.</p>
                    <div class="bib-vazia-tipos">
                        @foreach ($tiposInfo as $t => [$nome, $sub, $uso])
                            @continue($tipo && $tipo !== $t)
                            <div class="bib-vazia-tipo">
                                <span class="bib-tipo-icone">{!! $iconeTipo[$t] !!}</span>
                                <b>{{ $nome }}</b>
                                <small>{{ $sub }}</small>
                                <p>{{ $uso }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="biblioteca-grelha">
                    @foreach ($imagens as $img)
                        <div class="bib-item">
                            <a href="{{ $img->url() }}" class="bib-imagem tipo-{{ $img->tipo }}" title="Ver em grande">
                                <img src="{{ $img->url() }}" alt="{{ $img->descricao }}" loading="lazy">
                                <span class="bib-etiqueta">{!! $iconeTipo[$img->tipo] ?? '' !!} {{ $tiposInfo[$img->tipo][0] ?? $img->tipo }}</span>
                            </a>
                            <div class="bib-corpo">
                                <p class="bib-descricao">{{ $img->descricao }}</p>
                                <details class="bib-editar">
                                    <summary>Editar</summary>
                                    <form method="POST" action="{{ route('biblioteca.update', $img) }}" class="bib-form">
                                        @csrf
                                        <select name="tipo" aria-label="Tipo">
                                            @foreach (\App\Models\ImagemBiblioteca::TIPOS as $t => $nome)
                                                <option value="{{ $t }}" @selected($img->tipo === $t)>{{ $nome }}</option>
                                            @endforeach
                                        </select>
                                        <textarea name="descricao" rows="3" maxlength="300" required aria-label="Descrição">{{ $img->descricao }}</textarea>
                                        <div class="bib-acoes">
                                            <button type="submit" class="btn pequeno btn-principal">Guardar</button>
                                            <button type="submit" form="remover-{{ $img->id }}" class="btn pequeno btn-perigo">Remover</button>
                                        </div>
                                    </form>
                                </details>
                            </div>
                            <form method="POST" action="{{ route('biblioteca.destroy', $img) }}" id="remover-{{ $img->id }}"
                                  data-confirm="Remover esta imagem da biblioteca? Os posts que já a usam ficam iguais.">@csrf</form>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

<script>
// Adicionar imagens: arrastar e largar, miniaturas do que vai ser enviado, exemplo de descrição por tipo.
(function () {
    var zona = document.getElementById('bib-largar');
    var input = document.getElementById('bib-ficheiros');
    var lista = document.getElementById('bib-escolhidas');
    var descricao = document.getElementById('bib-descricao');

    function mostra() {
        lista.innerHTML = '';
        var ficheiros = Array.prototype.slice.call(input.files || []);
        lista.hidden = !ficheiros.length;
        zona.classList.toggle('com-ficheiros', ficheiros.length > 0);
        ficheiros.slice(0, 20).forEach(function (f) {
            var fig = document.createElement('figure');
            var img = document.createElement('img');
            img.src = URL.createObjectURL(f);
            img.alt = '';
            var nome = document.createElement('figcaption');
            nome.textContent = f.name;
            fig.appendChild(img);
            fig.appendChild(nome);
            lista.appendChild(fig);
        });
        if (ficheiros.length) {
            var total = document.createElement('p');
            total.textContent = ficheiros.length === 1 ? '1 imagem escolhida' : ficheiros.length + ' imagens escolhidas';
            lista.prepend(total);
        }
    }
    input.addEventListener('change', mostra);
    ['dragenter', 'dragover'].forEach(function (ev) {
        zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.add('a-largar'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        zona.addEventListener(ev, function () { zona.classList.remove('a-largar'); });
    });
    zona.addEventListener('drop', function (e) {
        e.preventDefault();
        if (e.dataTransfer && e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            mostra();
        }
    });
    document.querySelectorAll('input[name=tipo]').forEach(function (r) {
        r.addEventListener('change', function () { descricao.placeholder = r.dataset.exemplo; });
    });
    document.getElementById('bib-adicionar').addEventListener('submit', function () {
        var b = this.querySelector('.bib-guardar');
        b.disabled = true;
        b.textContent = 'A enviar…';
    });
})();
</script>
@endsection
