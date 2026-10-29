@extends('layout')

@section('title', 'Criar post')

@section('body')
@include('painel.topo')

<div class="pagina-estreita">
    <a href="{{ route('painel') }}" class="voltar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg> Voltar ao painel</a>
    <h1>Criar post</h1>
    <p class="sub">Escolha o tipo de post. Começa com um exemplo, que depois muda à vontade. Qualquer tipo pode levar uma imagem de fundo à escolha.
        @if ($data)<br>Data de publicação: <b>{{ \Illuminate\Support\Carbon::parse($data)->locale('pt_PT')->translatedFormat('l, j \d\e F') }}, 10:00</b> (pode mudar a hora a seguir).@endif
    </p>

    <form method="POST" action="{{ route('posts.store') }}" class="caixa criar">
        @csrf
        @if ($data)<input type="hidden" name="data" value="{{ $data }}">@endif
        <fieldset class="tipos">
            <legend>Tipo de post</legend>
            @php($descricoes = [
                'dica' => 'Conselho útil, com ícone e pontos (ex.: regra 3-2-1 de backup).',
                'produto' => 'Produto ou serviço, com foto oficial, vantagens e botão.',
                'aviso' => 'Fecho, horário, mudança. Título grande e data.',
                'evento' => 'Workshop, feira, webinar. Dia em destaque, hora e local.',
            ])
            @foreach ($modelos as $m)
                <label class="tipo">
                    <input type="radio" name="modelo" value="{{ $m }}" @checked(old('modelo', 'dica') === $m)>
                    <span><b>{{ ucfirst($m) }}</b><small>{{ $descricoes[$m] }}</small></span>
                </label>
            @endforeach
        </fieldset>

        <label for="title">Nome do post <small>(só aparece no painel)</small></label>
        <input id="title" name="title" required maxlength="150" value="{{ old('title') }}"
               placeholder="Ex.: UPS Riello para servidores">
        @error('title')<div class="erro">{{ $message }}</div>@enderror

        <button type="submit" class="btn btn-principal">Criar e editar</button>
    </form>
</div>
@endsection
