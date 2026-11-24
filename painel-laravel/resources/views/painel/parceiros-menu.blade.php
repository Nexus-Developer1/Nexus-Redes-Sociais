{{-- Menu "⋯" (cartão e editor): enviar também por email aos parceiros e o email de teste. --}}
@if ($post->parceiros_enviado_em)
    <p class="mais-separado mais-nota">✉ Enviado por email aos parceiros {{ $post->parceiros_enviado_em->locale('pt_PT')->translatedFormat('j \d\e F, H:i') }}.</p>
@elseif ($post->isEditable() && ! $post->aRefazer())
    <form method="POST" action="{{ route('posts.partners', $post) }}" class="mais-separado">
        @csrf
        <input type="hidden" name="enviar" value="{{ $post->enviar_parceiros ? 0 : 1 }}">
        <button type="submit" class="mais-item">✉ {{ $post->enviar_parceiros ? 'Não enviar aos parceiros' : 'Enviar também aos parceiros por email' }}</button>
    </form>
@endif
@if ($post->enviar_parceiros && ! $post->parceiros_enviado_em && $post->image_path)
    <form method="POST" action="{{ route('posts.partnersTest', $post) }}" data-a-enviar="A enviar o teste…">
        @csrf
        <button type="submit" class="mais-item">Enviar-me um teste (como os parceiros o recebem)</button>
    </form>
@endif
