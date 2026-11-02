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
</script>
</body>
</html>
