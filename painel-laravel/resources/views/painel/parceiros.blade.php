@extends('layout')

@section('title', 'Parceiros')

@section('body')
@php($sairam = $parceiros->count() - $ativos)

<div class="pagina">
    <x-cabecalho titulo="Parceiros" sub="Recebem por email os posts marcados com “Enviar também aos parceiros”, à hora da publicação e só depois de aprovados." />

    @if (session('ok'))<div class="aviso" role="status">{{ session('ok') }}</div>@endif
    @if (session('erro'))<div class="aviso erro" role="alert">{{ session('erro') }}</div>@endif
    @if ($errors->any())
        <div class="aviso erro" role="alert">@foreach ($errors->all() as $e){{ $e }}<br>@endforeach</div>
    @endif

    <div class="parceiros-forms">
        <form method="POST" action="{{ route('parceiros.store') }}" class="bloco formulario">
            @csrf
            <h2>Adicionar um parceiro</h2>
            <div class="dupla">
                <label>Nome <input name="nome" required maxlength="150" value="{{ old('nome') }}" placeholder="Ex.: Ana Costa"></label>
                <label>Empresa (opcional) <input name="empresa" maxlength="150" value="{{ old('empresa') }}" placeholder="Ex.: InfoPorto, Lda."></label>
            </div>
            <label>Email <input type="email" name="email" required maxlength="190" value="{{ old('email') }}" placeholder="ana@empresa.pt"></label>
            <button type="submit" class="btn btn-principal">Adicionar</button>
        </form>

        <form method="POST" action="{{ route('parceiros.importar') }}" class="bloco formulario" enctype="multipart/form-data">
            @csrf
            <h2>Juntar uma lista</h2>
            <label>Cole aqui do Excel <small>uma pessoa por linha: nome, empresa e email (por esta ordem ou só o email)</small>
                <textarea name="lista" rows="4" placeholder="Ana Costa&#9;InfoPorto&#9;ana@infoporto.pt">{{ old('lista') }}</textarea>
            </label>
            <label>ou escolha um ficheiro CSV <input type="file" name="ficheiro" accept=".csv,.txt,text/csv"></label>
            <small>Emails que já estão na lista ficam como estão: quem pediu para sair não volta a receber.</small>
            <button type="submit" class="btn btn-contorno">Juntar à lista</button>
        </form>
    </div>

    @if ($parceiros->isEmpty())
        <div class="vazio">
            <h2>Ainda não há parceiros na lista</h2>
            <p>Adicione-os aqui. Depois, no menu “⋯” de um post, escolha “Enviar também aos parceiros por email”.</p>
        </div>
    @else
        <div class="bloco lista-users">
            <p class="parceiros-resumo">
                <b>{{ $ativos }}</b> {{ $ativos === 1 ? 'recebe' : 'recebem' }} os emails
                @if ($sairam) · {{ $sairam }} {{ $sairam === 1 ? 'deixou' : 'deixaram' }} de receber @endif
            </p>
            @foreach ($parceiros as $p)
                <div class="user-linha {{ $p->ativo ? '' : 'parceiro-fora' }}">
                    <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($p->nome, 0, 1)) }}</span>
                    <div class="user-info">
                        <b>{{ $p->nome }}</b>@if ($p->empresa) <small>· {{ $p->empresa }}</small>@endif
                        <div class="origem">{{ $p->email }}</div>
                    </div>
                    @unless ($p->ativo)
                        <span class="estado estado-rejected" title="Desde {{ $p->saiu_em?->locale('pt_PT')->translatedFormat('j \d\e F \d\e Y') }}">Deixou de receber</span>
                    @endunless
                    <div class="user-acoes">
                        <form method="POST" action="{{ route('parceiros.estado', $p) }}">
                            @csrf<button type="submit" class="btn pequeno">{{ $p->ativo ? 'Parar de enviar' : 'Voltar a enviar' }}</button>
                        </form>
                        <form method="POST" action="{{ route('parceiros.destroy', $p) }}"
                              data-confirm="Remover {{ $p->nome }} ({{ $p->email }}) da lista? Se pediu para sair, use antes “Parar de enviar”: assim não volta a entrar numa lista importada.">
                            @csrf<button type="submit" class="btn pequeno btn-perigo">Remover</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <p class="faixa-info" role="note">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
        Cada email leva um link para pedir para sair, que responde para {{ config('mail.from.address') }}. Quando alguém pedir, carregue em “Parar de enviar”.
    </p>
</div>
@endsection
