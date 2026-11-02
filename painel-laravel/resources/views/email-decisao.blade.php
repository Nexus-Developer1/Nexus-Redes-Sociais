@extends('layout')

@section('title', $acao === 'aprovar' ? 'Aprovar post' : 'Pedir alteração')

@section('body')
{{-- Aberta a partir do link do email (sem sessão iniciada). Nada é decidido só por abrir a página. --}}
<div class="decisao-email">
    <div class="decisao-cartao">
        <div class="decisao-topo">
            <img src="{{ asset('modelos/logo-nexus.png') }}" alt="NEXUS" width="150" height="28">
            <span>REDES SOCIAIS</span>
        </div>
        <div class="decisao-conteudo">

        @if ($feito)
            <div class="aviso" role="status">✓ {{ $feito }}</div>
        @elseif ($problema)
            <div class="aviso amarelo" role="alert">{{ $problema }}</div>
        @endif

        <div class="decisao-kicker">{{ $acao === 'aprovar' ? 'APROVAR POST' : 'PEDIR ALTERAÇÃO' }}</div>
        <h1>{{ $post->title }}</h1>
        <div class="decisao-chips">
            <span><b>{{ $post->whenLabel() }}</b></span>
            <span>{{ implode(' · ', $post->networks ?? []) }}</span>
            @if ($post->imagens_carrossel)<span class="forte">Carrossel · {{ count($post->imagens_carrossel) }} imagens</span>@endif
        </div>

        @if ($post->image_path)
            <div class="decisao-imagens">
                @foreach ($post->imagens() as $i => $img)
                    <a href="{{ asset($img) }}" target="_blank" rel="noopener"><img src="{{ asset($img) }}" alt="Imagem {{ $i + 1 }}" loading="lazy"></a>
                @endforeach
                @if ($post->temStories() && $post->imagem_story)
                    <a href="{{ asset($post->imagem_story) }}" target="_blank" rel="noopener" class="decisao-story" title="Versão dos Stories">
                        <img src="{{ asset($post->imagem_story) }}" alt="Versão dos Stories" loading="lazy"></a>
                @endif
            </div>
        @endif

        @if ($post->caption)
            <div class="decisao-rotulo">LEGENDA</div>
            <div class="decisao-legenda">{{ $post->caption }}</div>
        @endif

        @if (! $feito && ! $problema)
            <form method="POST" action="{{ $destino }}" class="decisao-form"
                  onsubmit="var b = this.querySelector('button[type=submit]'); b.disabled = true; b.textContent = 'A enviar…';">
                @csrf
                @if ($acao === 'aprovar')
                    <p>Ao confirmar, o post fica aprovado em nome de <b>{{ $user->name }}</b> e é publicado à hora marcada.</p>
                    <button type="submit" class="btn btn-principal">✓ Confirmar aprovação</button>
                @else
                    @if ($errors->any())<div class="aviso erro" role="alert">{{ $errors->first() }}</div>@endif
                    <label for="comment">O que quer mudar?</label>
                    <textarea id="comment" name="comment" rows="4" maxlength="2000" required autofocus
                              placeholder="Ex.: o título está muito comprido; use uma foto de um escritório">{{ old('comment') }}</textarea>
                    <button type="submit" class="btn btn-principal">Enviar pedido de alteração</button>
                @endif
            </form>
        @endif

        <p class="decisao-rodape"><a href="{{ url('/').'#post-'.$post->id }}">Abrir no painel</a>
            · Nada é publicado sem aprovação de uma pessoa.</p>
        </div>
    </div>
</div>
@endsection
