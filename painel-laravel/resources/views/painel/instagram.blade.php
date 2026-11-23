{{--
    Pré-visualização aproximada de um post no feed do Instagram da NEXUS (conta nexus.solutions):
    cabeçalho com a conta, imagem (ou carrossel), ações, legenda cortada no "… mais" como no telemóvel e as
    verificações das regras do Instagram (tamanho da legenda, hashtags, links). A legenda é desenhada em JS
    (layout.blade.php), para o editor a poder atualizar enquanto se escreve.
    $fonteLegenda: seletor do campo com a legenda em edição (no editor), em vez da legenda guardada.
--}}
@php
    $imagensIg = $post->imagens();
    $fonteLegenda = $fonteLegenda ?? null;
@endphp
<dialog class="ig-dialogo" id="ig-{{ $post->id }}" aria-labelledby="ig-titulo-{{ $post->id }}"
        @if ($fonteLegenda) data-fonte="{{ $fonteLegenda }}" @endif>
    <div class="ig-janela">
        <div class="ig-topo">
            <h2 id="ig-titulo-{{ $post->id }}" tabindex="-1" autofocus>Como fica no Instagram <small>(aproximado)</small></h2>
            <button type="button" class="ig-fechar" data-fechar-dialogo aria-label="Fechar">×</button>
        </div>
        <div class="ig-corpo">
            <article class="ig-post" aria-label="Post no feed do Instagram">
                <header class="ig-cabeca">
                    <span class="ig-avatar"><img src="{{ asset('modelos/logo-nexus.png') }}" alt="" width="150" height="28"></span>
                    <span class="ig-conta"><b>nexus.solutions</b><small>NEXUS IT Solutions</small></span>
                    <span class="ig-mais" aria-hidden="true">•••</span>
                </header>
                <div class="ig-media">
                    @if ($imagensIg)
                        <div class="ig-galeria" tabindex="0" @if (count($imagensIg) > 1) aria-label="Carrossel com {{ count($imagensIg) }} imagens" @endif>
                            @foreach ($imagensIg as $i => $img)
                                <img src="{{ \App\Support\Miniatura::url($img, 720) }}" alt="{{ $i ? 'Imagem '.($i + 1) : ($post->image_alt ?? $post->title) }}" loading="lazy">
                            @endforeach
                        </div>
                        @if (count($imagensIg) > 1)
                            <span class="ig-contador">1/{{ count($imagensIg) }}</span>
                            <div class="ig-pontos" aria-hidden="true">@foreach ($imagensIg as $i => $img)<i @class(['ativo' => $i === 0])></i>@endforeach</div>
                        @endif
                    @else
                        <div class="ig-sem-imagem">Ainda sem imagem. Carregue em “Guardar e gerar imagem”.</div>
                    @endif
                </div>
                <div class="ig-acoes" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M12 20s-7-4.4-9.2-8.6C1.3 8.4 3 5 6.4 5c2 0 3.2 1.1 3.9 2.2l1.7 2 1.7-2C14.4 6.1 15.6 5 17.6 5 21 5 22.7 8.4 21.2 11.4 19 15.6 12 20 12 20z"/></svg>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M20.5 11.5a8.5 8.5 0 0 1-12.6 7.4L3.5 20l1.2-4.2A8.5 8.5 0 1 1 20.5 11.5z"/></svg>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M21.5 3.5L2.8 10.2l7 2.9 2.9 7 8.8-16.6z"/><path d="M9.8 13.1l11.7-9.6"/></svg>
                    <svg class="ig-guardar" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M6 3.5h12v17l-6-4.6-6 4.6z"/></svg>
                </div>
                <div class="ig-legenda" data-ig-legenda></div>
                <p class="ig-data">{{ $post->scheduled_at ? mb_strtoupper($post->scheduled_at->locale('pt_PT')->translatedFormat('j \d\e F')) : 'SEM DATA' }}</p>
            </article>

            <aside class="ig-verificar" aria-label="Verificações da legenda">
                <h3>Verificações</h3>
                <ul data-ig-verificacoes></ul>
                <p class="ig-nota">No telemóvel, só a 1.ª ou 2.ª linha da legenda aparece antes do “… mais”: é aí que tem de estar a ideia principal.</p>
            </aside>
        </div>
    </div>
    <script type="application/json" data-ig-texto>@json((string) $post->caption)</script>
</dialog>
