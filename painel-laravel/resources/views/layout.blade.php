<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Painel de aprovação') · NEXUS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/painel.css') }}?v={{ filemtime(public_path('css/painel.css')) }}">
</head>
<body class="@yield('body_class')">
@yield('body')
<script>
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
        var a = e.target.closest('.post-imagem a[href], .decisao-imagens a[href]');
        if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button) return; // Ctrl+clique continua a abrir noutro separador
        e.preventDefault();
        galeria = a.closest('.carrossel-galeria');
        var links = Array.prototype.slice.call((galeria || a.closest('.decisao-imagens') || a.parentNode).querySelectorAll('a[href]'));
        lista = links.map(function (l) { return l.href; });
        if (!dlg) cria();
        dlg.showModal();
        mostra(links.indexOf(a));
    });
})();
</script>
</body>
</html>
