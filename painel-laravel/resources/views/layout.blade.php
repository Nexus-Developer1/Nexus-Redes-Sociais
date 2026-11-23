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
                <button class="topo-movel__menu" type="button" data-abrir-lateral aria-label="Abrir menu" aria-expanded="false" aria-controls="menu-lateral">
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
    var botaoMenu = document.querySelector('[data-abrir-lateral]');
    function abrir() { lateral.classList.add('aberta'); if (veu) veu.hidden = false; if (botaoMenu) botaoMenu.setAttribute('aria-expanded', 'true'); var p = lateral.querySelector('a, button'); if (p) p.focus(); }
    function fechar() { lateral.classList.remove('aberta'); if (veu) veu.hidden = true; if (botaoMenu) { botaoMenu.setAttribute('aria-expanded', 'false'); botaoMenu.focus(); } }
    // Com o menu aberto no telemóvel, o Tab não sai da barra lateral.
    lateral.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || !lateral.classList.contains('aberta')) return;
        var f = lateral.querySelectorAll('a, button'); var p = f[0], u = f[f.length - 1];
        if (e.shiftKey && document.activeElement === p) { e.preventDefault(); u.focus(); }
        else if (!e.shiftKey && document.activeElement === u) { e.preventDefault(); p.focus(); }
    });
    document.querySelectorAll('[data-abrir-lateral]').forEach(function (b) { b.addEventListener('click', abrir); });
    document.querySelectorAll('[data-fechar-lateral]').forEach(function (b) { b.addEventListener('click', fechar); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && lateral.classList.contains('aberta')) fechar(); });
})();

// Formulários com data-confirm pedem confirmação. O texto vem de um atributo HTML (nunca de código JS),
// por isso nomes ou textos escritos por utilizadores não podem correr como código.
document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    // data-sair-sem-aviso (ex.: "Eliminar post"): já confirmou, não pergunta outra vez pelas alterações por guardar
    if (e.target.hasAttribute('data-sair-sem-aviso')) window.SAIR_SEM_AVISO = true;
    // data-a-enviar="A pedir…": o botão fica desligado com esse texto (evita carregar duas vezes)
    var texto = e.target.getAttribute('data-a-enviar');
    var botao = texto && e.target.querySelector('button[type=submit], button:not([type])');
    if (botao) { setTimeout(function () { botao.disabled = true; botao.textContent = texto; }, 0); }
});

// Pré-visualização do Instagram (painel/instagram.blade.php): legenda como no telemóvel e verificações.
(function () {
    var VISIVEL = 125; // caracteres que o Instagram mostra antes do "… mais" (mais ou menos duas linhas)

    // Texto com #hashtags e @menções a azul. Tudo entra como texto (nunca como HTML).
    function pinta(destino, texto) {
        texto.split(/((?<![\w@.])#[\p{L}\p{N}_]+|(?<![\w.])@[\w.]*\w)/u).forEach(function (parte) {
            if (!parte) return;
            if (/^[#@]./.test(parte) && !/\s/.test(parte)) { var s = document.createElement('span'); s.className = 'ig-tag'; s.textContent = parte; destino.appendChild(s); }
            else destino.appendChild(document.createTextNode(parte));
        });
    }

    function corte(texto) {
        var quebra = texto.indexOf('\n');
        if (quebra >= 0 && quebra <= VISIVEL) return texto.slice(0, quebra);
        if (texto.length <= VISIVEL) return texto;
        var c = texto.slice(0, VISIVEL); var espaco = c.lastIndexOf(' ');
        return espaco > 60 ? c.slice(0, espaco) : c;
    }

    function legenda(caixa, texto) {
        caixa.textContent = '';
        var p = document.createElement('p');
        var conta = document.createElement('b'); conta.textContent = 'nexus.solutions '; p.appendChild(conta);
        if (!texto.trim()) { var v = document.createElement('i'); v.textContent = '(sem legenda)'; p.appendChild(v); caixa.appendChild(p); return; }
        var curto = corte(texto);
        var corpo = document.createElement('span');
        pinta(corpo, curto.length < texto.length ? curto : texto);
        p.appendChild(corpo);
        if (curto.length < texto.length) {
            var mais = document.createElement('button'); mais.type = 'button'; mais.className = 'ig-ver-mais'; mais.textContent = '… mais';
            mais.addEventListener('click', function () { corpo.textContent = ''; pinta(corpo, texto); mais.remove(); });
            p.appendChild(mais);
        }
        caixa.appendChild(p);
    }

    function verifica(lista, texto) {
        lista.textContent = '';
        var tags = texto.match(/(?<![\w@.])#[\p{L}\p{N}_]+/gu) || [];
        var itens = [];
        itens.push(texto.length <= 2200
            ? ['ok', 'Tamanho: ' + texto.length + ' de 2200 caracteres.']
            : ['erro', 'Legenda com ' + texto.length + ' caracteres: o Instagram só aceita 2200.']);
        if (tags.length > 30) itens.push(['erro', tags.length + ' hashtags: o Instagram recusa mais de 30.']);
        else if (tags.length === 0) itens.push(['aviso', 'Sem hashtags: ponha algumas no fim (ex.: #NEXUS) para chegar a mais pessoas.']);
        else if (tags.length > 15) itens.push(['aviso', tags.length + ' hashtags: funciona, mas 5 a 10 bem escolhidas costumam resultar melhor.']);
        else itens.push(['ok', tags.length + (tags.length === 1 ? ' hashtag.' : ' hashtags.')]);
        var primeira = corte(texto).trim();
        if (texto.trim()) itens.push(primeira.length >= 20
            ? ['ok', 'Antes do “… mais” aparece: “' + primeira.slice(0, 80) + (primeira.length > 80 ? '…' : '') + '”']
            : ['aviso', 'A primeira linha é muito curta: é a única coisa que se lê sem abrir a legenda.']);
        if (/https?:\/\/|www\.|\b[\w-]+\.(pt|com|net|org|eu)(\/\S*)?\b/i.test(texto))
            itens.push(['info', 'Os endereços na legenda não abrem com um toque no Instagram (no Facebook abrem). Pode acrescentar “link na bio”.']);
        itens.forEach(function (i) { var li = document.createElement('li'); li.className = 'ig-v-' + i[0]; li.textContent = i[1]; lista.appendChild(li); });
    }

    function texto(dlg) {
        var fonte = dlg.dataset.fonte && document.querySelector(dlg.dataset.fonte);
        if (fonte) return fonte.value;
        try { return JSON.parse(dlg.querySelector('[data-ig-texto]').textContent) || ''; } catch (e) { return ''; }
    }

    function desenha(dlg) {
        var t = texto(dlg);
        legenda(dlg.querySelector('[data-ig-legenda]'), t);
        verifica(dlg.querySelector('[data-ig-verificacoes]'), t);
    }

    document.addEventListener('click', function (e) {
        var abrir = e.target.closest('[data-ig-abrir]');
        if (abrir) {
            var dlg = document.getElementById('ig-' + abrir.dataset.igAbrir);
            if (dlg) { desenha(dlg); dlg.showModal(); }
            return;
        }
        var fechar = e.target.closest('[data-fechar-dialogo]');
        if (fechar) { fechar.closest('dialog').close(); return; }
        if (e.target.tagName === 'DIALOG' && (e.target.classList.contains('ig-dialogo') || e.target.classList.contains('dialogo'))) e.target.close(); // clicar fora
        var abreDlg = e.target.closest('[data-abrir-dialogo]');
        if (abreDlg) { var d = document.getElementById(abreDlg.dataset.abrirDialogo); if (d) d.showModal(); }
    });

    // No editor: a legenda da pré-visualização acompanha o que se escreve.
    document.querySelectorAll('.ig-dialogo[data-fonte]').forEach(function (dlg) {
        var fonte = document.querySelector(dlg.dataset.fonte);
        if (fonte) fonte.addEventListener('input', function () { if (dlg.open) desenha(dlg); });
    });

    // Carrossel: contador "2/5" e pontos.
    document.querySelectorAll('.ig-galeria').forEach(function (g) {
        var media = g.parentNode, total = g.children.length;
        if (total < 2) return;
        g.addEventListener('scroll', function () {
            var i = Math.round(g.scrollLeft / g.clientWidth);
            var c = media.querySelector('.ig-contador'); if (c) c.textContent = (i + 1) + '/' + total;
            media.querySelectorAll('.ig-pontos i').forEach(function (p, k) { p.classList.toggle('ativo', k === i); });
        });
    });
})();

// Janelas que têm de abrir logo (ex.: "Pedir um post" com um erro para corrigir).
document.querySelectorAll('dialog[data-abrir-ao-carregar]').forEach(function (d) { d.showModal(); });

// Menu "⋯" dos cartões: só um aberto de cada vez e fecha ao clicar fora.
document.addEventListener('click', function (e) {
    document.querySelectorAll('details.mais-opcoes[open]').forEach(function (d) { if (!d.contains(e.target)) d.open = false; });
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('details.mais-opcoes[open]').forEach(function (d) { d.open = false; d.querySelector('summary').focus(); });
});

// "Cancelar" dentro de um <details> (Pedir alteração, Trocar tema) fecha-o.
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-fechar-details]');
    if (b) { var d = b.closest('details'); if (d) d.open = false; }
});

// Formulários com data-avisar-saida (o editor): avisa antes de sair da página com alterações por guardar.
document.querySelectorAll('form[data-avisar-saida]').forEach(function (f) {
    var mudou = false;
    f.addEventListener('input', function () { mudou = true; });
    f.addEventListener('change', function () { mudou = true; });
    f.addEventListener('submit', function () { mudou = false; });
    window.addEventListener('beforeunload', function (e) { if (mudou && !window.SAIR_SEM_AVISO) { e.preventDefault(); e.returnValue = ''; } });
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
        if (Date.now() - inicio > 15 * 60 * 1000) { // desiste ao fim de 15 minutos, mas diz porquê
            var nota = document.createElement('div');
            nota.className = 'aviso erro'; nota.setAttribute('role', 'status');
            nota.textContent = 'A IA está a demorar mais do que o costume. Atualize a página daqui a pouco para ver se já acabou.';
            var alvo = document.querySelector('.pagina, .area, body'); alvo.insertBefore(nota, alvo.firstChild);
            return;
        }
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
