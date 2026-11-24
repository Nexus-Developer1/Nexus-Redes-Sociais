{{-- Menu "⋯" (cartão): a vertente email aos parceiros (ver, testar e, só como exceção, não enviar). --}}
@if ($post->enviar_parceiros && ($post->design || $post->image_path))
    <a class="mais-item mais-separado" href="{{ route('posts.partnersPreview', $post) }}" target="_blank" rel="noopener">✉ Ver o email dos parceiros ↗</a>
@endif
@if ($post->parceiros_enviado_em)
    <p class="mais-nota">✉ Enviado por email aos parceiros {{ $post->parceiros_enviado_em->locale('pt_PT')->translatedFormat('j \d\e F, H:i') }}.</p>
@else
    @if ($post->enviar_parceiros && $post->image_path)
        <form method="POST" action="{{ route('posts.partnersTest', $post) }}" data-a-enviar="A enviar o teste…">
            @csrf
            <button type="submit" class="mais-item">Enviar-me um teste do email</button>
        </form>
    @endif
    @if ($post->isEditable() && ! $post->aRefazer())
        <form method="POST" action="{{ route('posts.partners', $post) }}">
            @csrf
            <input type="hidden" name="enviar" value="{{ $post->enviar_parceiros ? 0 : 1 }}">
            <button type="submit" class="mais-item">{{ $post->enviar_parceiros ? 'Não enviar este aos parceiros (exceção)' : '✉ Voltar a enviar aos parceiros por email' }}</button>
        </form>
    @endif
@endif
