{{-- "Eliminar post" num menu "⋯" (cartão e editor). Publicados não aparecem; aprovados dizem porque não dá. --}}
@php($motivoNaoEliminar = $post->motivoParaNaoEliminar())
@if (! $motivoNaoEliminar)
    <form method="POST" action="{{ route('posts.destroy', $post) }}" class="mais-separado" data-sair-sem-aviso
          data-confirm="Eliminar “{{ $post->title }}” de vez? As imagens e o histórico deste post também são apagados e não dá para desfazer.">
        @csrf
        <button type="submit" class="mais-item mais-perigo">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/></svg>
            Eliminar post
        </button>
    </form>
@elseif ($post->status !== \App\Models\Post::PUBLISHED && ! $post->publicadoAlgures())
    <p class="mais-separado mais-nota">{{ $motivoNaoEliminar }}</p>
@endif
