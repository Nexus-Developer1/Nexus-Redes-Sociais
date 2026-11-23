<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0A2A18">
    <title>@yield('title', 'Painel de aprovação') · NEXUS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- ADN Nexus: a letra Poppins e o sistema de desenho partilhado (suite.css, igual no portal, na Nexus Infra
         e na Knowledgebase); depois o que é próprio deste painel. --}}
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="{{ asset('css/suite.css') }}?v={{ filemtime(public_path('css/suite.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/painel.css') }}?v={{ filemtime(public_path('css/painel.css')) }}">
    <link rel="icon" href="{{ asset('img/icon-192.png') }}" sizes="192x192">
    <link rel="apple-touch-icon" href="{{ asset('img/apple-touch-icon.png') }}">
</head>
<body class="@yield('body_class')">
@if (auth()->check() && ! View::hasSection('sem_lateral'))
    <a class="skip-link" href="#conteudo">Saltar para o conteúdo</a>
    <div class="app">
        <button class="lateral__veu" type="button" data-fechar-lateral hidden aria-label="Fechar menu"></button>
        @include('painel.lateral')
        <div class="trabalho">
            <header class="topo-movel">
                <button class="topo-movel__menu" type="button" data-abrir-lateral aria-label="Abrir menu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <img src="{{ asset('modelos/logo-nexus.png') }}" alt="Nexus" width="150" height="28" class="topo-movel__logo">
            </header>
            <main id="conteudo" class="area" tabindex="-1">
                @yield('body')
            </main>
        </div>
    </div>
@else
    @yield('body')
@endif
<script>
// Barra lateral no telemóvel: abrir, fechar e devolver o foco (igual ao portal). Em ecrãs largos está sempre visível.
(function () {
    var lateral = document.querySelector('[data-lateral]');
    var veu = document.querySelector('.lateral__veu');
    if (!lateral) return;
    function abrir() { lateral.classList.add('aberta'); if (veu) veu.hidden = false; var p = lateral.querySelector('a, button'); if (p) p.focus(); }
    function fechar() { lateral.classList.remove('aberta'); if (veu) veu.hidden = true; var b = document.querySelector('[data-abrir-lateral]'); if (b) b.focus(); }
    document.querySelectorAll('[data-abrir-lateral]').forEach(function (b) { b.addEventListener('click', abrir); });
    document.querySelectorAll('[data-fechar-lateral]').forEach(function (b) { b.addEventListener('click', fechar); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && lateral.classList.contains('aberta')) fechar(); });
})();

// Formulários com data-confirm pedem confirmação. O texto vem de um atributo HTML (nunca de código JS),
// por isso nomes ou textos escritos por utilizadores não podem correr como código.
document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
});

// Carrossel nos cartões: setas ‹ › e contador "1 / 7" (no telemóvel também dá para deslizar com o dedo).
document.querySelectorAll('[data-carrossel]').forEach(function (c) {
    var g = c.querySelector('.carrossel-galeria');
    var total = g.children.length;
    var atual = function () { return Math.round(g.scrollLeft / g.clientWidth); };
    function mostra() {
        var i = atual();
        c.querySelector('.carrossel-conta').textContent = 'Carrossel · ' + (i + 1) + ' / ' + total;
        c.querySelector('.carrossel-seta.ant').hidden = i === 0;
        c.querySelector('.carrossel-seta.seg').hidden = i >= total - 1;
    }
    c.querySelectorAll('.carrossel-seta').forEach(function (b) {
        b.addEventListener('click', function () {
            var i = Math.max(0, Math.min(total - 1, atual() + (b.classList.contains('seg') ? 1 : -1)));
            g.scrollTo({ left: i * g.clientWidth, behavior: 'smooth' });
        });
    });
    g.addEventListener('scroll', function () { window.requestAnimationFrame(mostra); });
    mostra();
});

@auth
// Quando a IA está a trabalhar (fazer um post, refazer, trocar tema, propor ideias), a página pergunta ao
// servidor de 4 em 4 segundos como está. Quando acaba, salta sozinha para o post em "Para aprovar"
// (ou atualiza-se), sem ser preciso carregar em F5.
(function () {
    var marcas = Array.prototype.slice.call(document.querySelectorAll('[data-espera]'));
    if (!marcas.length) return;
    var ESTADO = @json(route('ia.estado'));
    var FILA = @json(route('painel'));
    var ids = function (tipos) {
        return marcas.filter(function (m) { return tipos.indexOf(m.dataset.espera) >= 0; }).map(function (m) { return m.dataset.id; }).join(',');
    };
    var url = ESTADO + '?posts=' + ids(['post', 'tema']) + '&pedidos=' + ids(['pedido']);
    var inicio = Date.now();

    function abrePronto(id) { window.location.href = FILA + '?pronto=' + id + '#post-' + id; }

    function verifica() {
        if (Date.now() - inicio > 15 * 60 * 1000) return; // desiste ao fim de 15 minutos
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j) return setTimeout(verifica, 8000);
                for (var k = 0; k < marcas.length; k++) {
                    var m = marcas[k], id = m.dataset.id, s;
                    if (m.dataset.espera === 'post') {
                        s = j.posts[id];
                        if (s && s.estado === 'pending') return abrePronto(id);       // pronto para aprovar
                        if (!s || s.estado !== m.dataset.estado) return location.reload(); // falhou ou mudou
                    } else if (m.dataset.espera === 'tema') {
                        s = j.posts[id];
                        if (!s || !s.troca) return location.reload();                // tema novo
                    } else if (m.dataset.espera === 'pedido') {
                        s = j.pedidos[id];
                        if (s && s.estado === 'done' && s.post) return abrePronto(s.post);
                        if (!s || s.estado === 'failed') return location.reload();
                    } else if (m.dataset.espera === 'propor') {
                        if (!j.propor) return location.reload();                     // ideias novas no calendário
                    }
                }
                setTimeout(verifica, 4000);
            })
            .catch(function () { setTimeout(verifica, 8000); });
    }
    setTimeout(verifica, 3000);
})();
@endauth

// Visor: clicar numa imagem de um post abre-a em grande, sem sair do painel. Num carrossel passa-se
// às outras arrastando (rato ou dedo), com as setas ou com ← →. Esc ou clicar fora fecha.
(function () {
    var dlg, foto, conta, original, lista = [], atual = 0, galeria = null;

    function cria() {
        dlg = document.createElement('dialog');
        dlg.className = 'visor';
        dlg.setAttribute('aria-label', 'Imagem do post');
        // HTML fixo; os endereços das imagens entram só como atributos (src / href)
        dlg.innerHTML = '<button type="button" class="visor-fechar" aria-label="Fechar">×</button>'
            + '<button type="button" class="visor-seta ant" aria-label="Imagem anterior">‹</button>'
            + '<img alt="" draggable="false">'
            + '<button type="button" class="visor-seta seg" aria-label="Imagem seguinte">›</button>'
            + '<div class="visor-baixo"><span class="visor-conta"></span>'
            + '<a class="visor-original" target="_blank" rel="noopener">Abrir em tamanho real ↗</a></div>';
        document.body.appendChild(dlg);
        foto = dlg.querySelector('img');
        conta = dlg.querySelector('.visor-conta');
        original = dlg.querySelector('.visor-original');

        dlg.querySelector('.visor-fechar').addEventListener('click', function () { dlg.close(); });
        dlg.querySelector('.ant').addEventListener('click', function () { mostra(atual - 1); });
        dlg.querySelector('.seg').addEventListener('click', function () { mostra(atual + 1); });
        dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); }); // clicar fora da imagem
        dlg.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') mostra(atual - 1);
            if (e.key === 'ArrowRight') mostra(atual + 1);
        });
        dlg.addEventListener('close', function () {
            // o cartão fica na imagem em que se parou
            if (galeria) galeria.scrollTo({ left: atual * galeria.clientWidth });
        });

        // Arrastar para o lado (rato ou dedo): mais de 60 px muda de imagem.
        var x0 = null;
        foto.addEventListener('pointerdown', function (e) {
            if (lista.length < 2) return;
            x0 = e.clientX;
            foto.setPointerCapture(e.pointerId);
            foto.classList.add('a-arrastar');
        });
        foto.addEventListener('pointermove', function (e) {
            if (x0 !== null) foto.style.transform = 'translateX(' + (e.clientX - x0) + 'px)';
        });
        function solta(e) {
            if (x0 === null) return;
            var dx = e.clientX - x0;
            x0 = null;
            foto.classList.remove('a-arrastar');
            foto.style.transform = '';
            if (dx < -60) mostra(atual + 1);
            else if (dx > 60) mostra(atual - 1);
        }
        foto.addEventListener('pointerup', solta);
        foto.addEventListener('pointercancel', solta);
    }

    function mostra(n) {
        atual = Math.max(0, Math.min(lista.length - 1, n));
        foto.src = lista[atual];
        original.href = lista[atual];
        conta.textContent = lista.length > 1 ? (atual + 1) + ' / ' + lista.length : '';
        dlg.querySelector('.ant').hidden = atual === 0;
        dlg.querySelector('.seg').hidden = atual >= lista.length - 1;
        foto.classList.toggle('arrastavel', lista.length > 1);
    }

    document.addEventListener('click', function (e) {
        var a = e.target.closest('.post-imagem a[href], .decisao-imagens a[href], a.bib-imagem[href]');
        if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button) return; // Ctrl+clique continua a abrir noutro separador
        e.preventDefault();
        galeria = a.closest('.carrossel-galeria');
        var grupo = galeria || a.closest('.decisao-imagens, .biblioteca-grelha') || a.parentNode;
        var links = Array.prototype.slice.call(grupo.querySelectorAll(a.classList.contains('bib-imagem') ? 'a.bib-imagem[href]' : 'a[href]'));
        lista = links.map(function (l) { return l.href; });
        if (!dlg) cria();
        dlg.showModal();
        mostra(links.indexOf(a));
    });
})();
</script>
</body>
</html>
