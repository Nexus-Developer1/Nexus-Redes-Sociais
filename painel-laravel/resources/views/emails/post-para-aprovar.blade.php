<x-mail::message>
# Post para aprovar

Olá{{ $nome ? ' '.$nome : '' }},

Há um post à espera da sua aprovação{{ $post->imagens_carrossel ? ' (carrossel com '.count($post->imagens_carrossel).' imagens, todas em anexo, por ordem)' : ' (imagem em anexo)' }}.

**{{ $post->title }}**<br>
{{ $post->whenLabel() }} · {{ implode(', ', $post->networks ?? []) }}

@if ($post->caption)
<x-mail::panel>
{{-- numa só linha HTML, para o markdown do email não mexer em listas, * ou _ da legenda --}}
{!! strtr(e($post->caption), ["\r" => '', "\n" => '<br>', '*' => '&#42;', '_' => '&#95;']) !!}
</x-mail::panel>
@endif

@if ($aprovar)
<x-mail::button :url="$aprovar" color="success">
✓ Aprovar
</x-mail::button>

<x-mail::button :url="$alterar" color="primary">
Pedir alteração
</x-mail::button>

Os botões abrem uma página para confirmar: nada é decidido só por abrir o link. Servem durante {{ $dias }} dias e só para esta versão do post.
@endif

[Abrir no painel]({{ $painel }}) · O painel só abre na rede da NEXUS. Nada é publicado sem aprovação de uma pessoa.

Painel de Redes Sociais NEXUS
</x-mail::message>
