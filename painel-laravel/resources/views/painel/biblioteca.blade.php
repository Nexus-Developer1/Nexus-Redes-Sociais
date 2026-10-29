@extends('layout')

@section('title', 'Biblioteca')

@section('body')
@include('painel.topo')

<div class="pagina-biblioteca">
    <div class="editor-barra">
        <a href="{{ route('painel') }}" class="voltar" title="Voltar ao painel"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg> Painel</a>
        <h1>Biblioteca da NEXUS</h1>
        <nav class="filtros" aria-label="Tipo de imagem">
            <a href="{{ route('biblioteca') }}" @class(['ativo' => ! $tipo])>Todas ({{ $contagem->sum() }})</a>
            @foreach (\App\Models\ImagemBiblioteca::TIPOS as $t => $nome)
                <a href="{{ route('biblioteca', ['tipo' => $t]) }}" @class(['ativo' => $tipo === $t])>{{ $nome }} ({{ $contagem[$t] ?? 0 }})</a>
            @endforeach
        </nav>
    </div>

    <div class="biblioteca">
        <div class="biblioteca-lado">
            @if (session('ok'))<div class="aviso" role="status">{{ session('ok') }}</div>@endif
            @if ($errors->any())
                <div class="aviso erro" role="alert">@foreach ($errors->all() as $e){{ $e }}<br>@endforeach</div>
            @endif

            <form method="POST" action="{{ route('biblioteca.store') }}" enctype="multipart/form-data" class="caixa formulario">
                @csrf
                <h2>Adicionar imagens</h2>
                <label>Tipo
                    <select name="tipo" required>
                        <option value="foto" @selected(old('tipo', $tipo) === 'foto')>Foto (equipa, instalações, escritório, eventos…)</option>
                        <option value="produto" @selected(old('tipo', $tipo) === 'produto')>Produto (foto oficial, de preferência PNG sem fundo)</option>
                        <option value="logo" @selected(old('tipo', $tipo) === 'logo')>Logótipo de parceiro (NVIDIA, Supermicro, Riello…)</option>
                    </select>
                </label>
                <label>Descrição <small>a IA lê isto para escolher: seja concreto</small>
                    <input name="descricao" required maxlength="300" value="{{ old('descricao') }}"
                           placeholder="Ex.: Workstation NEXUS Pro G3, torre preta · Equipa técnica a montar um servidor">
                </label>
                <label>Imagens <small>uma ou várias (máx. 20, até 20 MB cada)</small>
                    <input type="file" name="ficheiros[]" accept="image/png,image/jpeg,image/webp" multiple required>
                </label>
                <button type="submit" class="btn btn-principal">Guardar na biblioteca</button>
            </form>

            <div class="caixa dicas-biblioteca">
                <h2>Como a biblioteca é usada</h2>
                <ul>
                    <li><b>Editor:</b> botão “Da biblioteca…” na imagem de fundo, na foto do produto e no logótipo do parceiro.</li>
                    <li><b>IA:</b> nos pedidos automáticos procura primeiro uma <b>foto</b> vossa adequada ao tema; só depois vai ao banco de fotos grátis.</li>
                    <li><b>Produtos:</b> se o tema for sobre um produto daqui, a IA faz o “Produto em destaque” com a foto oficial e o logótipo do parceiro.</li>
                </ul>
            </div>
        </div>

        <div class="biblioteca-grelha">
            @forelse ($imagens as $img)
                <div class="bib-item">
                    <a href="{{ $img->url() }}" target="_blank" rel="noopener" class="bib-imagem tipo-{{ $img->tipo }}">
                        <img src="{{ $img->url() }}" alt="{{ $img->descricao }}" loading="lazy">
                    </a>
                    <form method="POST" action="{{ route('biblioteca.update', $img) }}" class="bib-form">
                        @csrf
                        <select name="tipo" aria-label="Tipo">
                            @foreach (\App\Models\ImagemBiblioteca::TIPOS as $t => $nome)
                                <option value="{{ $t }}" @selected($img->tipo === $t)>{{ $nome }}</option>
                            @endforeach
                        </select>
                        <textarea name="descricao" rows="2" maxlength="300" required aria-label="Descrição">{{ $img->descricao }}</textarea>
                        <div class="bib-acoes">
                            <button type="submit" class="btn pequeno">Guardar</button>
                            <button type="submit" form="remover-{{ $img->id }}" class="btn pequeno btn-perigo">Remover</button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('biblioteca.destroy', $img) }}" id="remover-{{ $img->id }}"
                          onsubmit="return confirm('Remover esta imagem da biblioteca? Os posts que já a usam ficam iguais.')">@csrf</form>
                </div>
            @empty
                <div class="vazio">Ainda não há imagens{{ $tipo ? ' deste tipo' : '' }}. Adicione as primeiras ao lado.</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
