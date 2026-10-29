/*
 * Modelos de posts NEXUS. Lê window.POST (vindo do dados.json do post) e monta o post.
 * Todo o texto entra como texto (nunca como HTML). No fim, ajusta o tamanho do título
 * até tudo caber e deixa no <body> data-estado="pronto" e data-avisos="..." para o
 * script scripts/gerar-post.ps1 verificar.
 */
(function () {
  'use strict';

  var BASE = document.currentScript.src.replace(/[^/]*$/, ''); // pasta modelos/
  var P = window.POST || {};
  var avisos = [];
  window.addEventListener('error', function (e) { avisos.push('erro no modelo: ' + e.message); });

  var FORMATOS = { '4:5': 'f-4x5', '1:1': 'f-1x1', '9:16': 'f-9x16' };
  var ESCALA = { '4:5': 1, '1:1': 0.86, '9:16': 1.1 };
  var TITULO = { dica: 88, produto: 80, aviso: 124, evento: 80 };
  var ETIQUETA = { dica: 'Dica', aviso: 'Aviso', evento: 'Evento', produto: '' };

  var I = 'stroke-width="7"';
  var ICONES = {
    escudo: '<svg viewBox="0 0 300 340"><path ' + I + ' d="M150 12 L272 58 V160 C272 240 216 298 150 326 C84 298 28 240 28 160 V58 Z" fill="rgba(20,120,50,.28)"/><path stroke-width="3" stroke-opacity=".45" d="M150 40 L248 77 V160 C248 225 204 272 150 297 C96 272 52 225 52 160 V77 Z"/><path d="M112 160 V132 a38 38 0 0 1 76 0 V160" stroke="#eafff0" stroke-width="12"/><rect x="96" y="158" width="108" height="88" rx="14" fill="#eafff0" stroke="none"/><circle cx="150" cy="194" r="12" fill="#0b5d2a" stroke="none"/><rect x="145" y="198" width="10" height="26" rx="4" fill="#0b5d2a" stroke="none"/></svg>',
    servidor: '<svg viewBox="0 0 300 300"><g ' + I + ' fill="rgba(20,120,50,.28)"><rect x="50" y="40" width="200" height="62" rx="12"/><rect x="50" y="119" width="200" height="62" rx="12"/><rect x="50" y="198" width="200" height="62" rx="12"/></g><g fill="#eafff0" stroke="none"><circle cx="82" cy="71" r="9"/><circle cx="82" cy="150" r="9"/><circle cx="82" cy="229" r="9"/></g><path ' + I + ' d="M150 71h70M150 150h70M150 229h70"/></svg>',
    nuvem: '<svg viewBox="0 0 300 300"><path ' + I + ' fill="rgba(20,120,50,.28)" d="M92 230 H214 a52 52 0 0 0 6-103.6 a74 74 0 0 0-140 14 a45 45 0 0 0 12 89.6 Z"/><path stroke="#eafff0" stroke-width="12" d="M150 206 V128 M118 158 L150 126 L182 158"/></svg>',
    rede: '<svg viewBox="0 0 300 300"><path ' + I + ' d="M150 150 L150 55 M150 150 L58 120 M150 150 L242 120 M150 150 L92 245 M150 150 L208 245 M58 120 L150 55 L242 120 L208 245 L92 245 Z" stroke-opacity=".8"/><g fill="#eafff0" stroke="none"><circle cx="150" cy="150" r="22"/><circle cx="150" cy="55" r="14"/><circle cx="58" cy="120" r="14"/><circle cx="242" cy="120" r="14"/><circle cx="92" cy="245" r="14"/><circle cx="208" cy="245" r="14"/></g></svg>',
    energia: '<svg viewBox="0 0 300 300"><rect ' + I + ' x="50" y="88" width="180" height="124" rx="18" fill="rgba(20,120,50,.28)"/><rect ' + I + ' x="230" y="124" width="20" height="52" rx="6"/><path fill="#eafff0" stroke="none" d="M152 104 L104 160 H142 L130 198 L182 138 H144 Z"/></svg>',
    monitor: '<svg viewBox="0 0 300 300"><rect ' + I + ' x="36" y="52" width="228" height="152" rx="14" fill="rgba(20,120,50,.28)"/><path ' + I + ' d="M150 204 V246 M108 248 H192"/><path stroke="#eafff0" stroke-width="10" d="M80 160 L120 120 L150 146 L210 96"/></svg>',
    chip: '<svg viewBox="0 0 300 300"><rect ' + I + ' x="82" y="82" width="136" height="136" rx="16" fill="rgba(20,120,50,.28)"/><rect x="118" y="118" width="64" height="64" rx="8" fill="#eafff0" stroke="none"/><path ' + I + ' d="M118 82V46M150 82V46M182 82V46M118 254v-36M150 254v-36M182 254v-36M82 118H46M82 150H46M82 182H46M254 118h-36M254 150h-36M254 182h-36"/></svg>',
    check: '<svg viewBox="0 0 24 24"><path stroke-width="3" d="M4.5 12.5l5 5L19.5 7"/></svg>',
    calendario: '<svg viewBox="0 0 24 24" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>',
    relogio: '<svg viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    local: '<svg viewBox="0 0 24 24" stroke-width="2"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>'
  };
  var REDE_FUNDO = '<svg viewBox="0 0 1080 1080" fill="none" stroke="#fff" stroke-width="2"><path d="M700 120L980 300L860 560ZM860 560L1040 760L980 300M580 380L700 120M580 380L860 560"/><g fill="#fff"><circle cx="700" cy="120" r="10"/><circle cx="980" cy="300" r="14"/><circle cx="860" cy="560" r="12"/><circle cx="1040" cy="760" r="9"/><circle cx="580" cy="380" r="8"/></g></svg>';

  // ---------- ajudas ----------
  function h(tag, cls, filhos) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    (filhos || []).forEach(function (f) {
      if (f === null || f === undefined || f === '') return;
      e.appendChild(typeof f === 'string' ? document.createTextNode(f) : f);
    });
    return e;
  }
  function icone(nome, cls) {
    var s = h('span', 'ico' + (cls ? ' ' + cls : ''));
    if (ICONES[nome]) s.innerHTML = ICONES[nome]; // SVG fixo deste ficheiro, nunca texto do post
    else avisos.push('ícone desconhecido: "' + nome + '" (existem: escudo, servidor, nuvem, rede, energia, monitor, chip)');
    return s;
  }
  // "\n" muda de linha; *texto* fica a verde.
  function rico(txt) {
    var frag = document.createDocumentFragment();
    String(txt || '').split('\n').forEach(function (linha, i) {
      if (i) frag.appendChild(document.createElement('br'));
      linha.split('*').forEach(function (parte, j) {
        if (parte) frag.appendChild(j % 2 ? h('em', null, [parte]) : document.createTextNode(parte));
      });
    });
    return frag;
  }
  function imagem(src, cls, oque) {
    var i = new Image();
    i.className = cls || '';
    i.alt = '';
    i.onerror = function () { avisos.push('não encontrei ' + oque + ': ' + src); i.remove(); };
    i.src = src;
    return i;
  }
  function titulo(txt) {
    if (!txt) avisos.push('falta o título');
    var d = h('div', 'titulo');
    String(txt || '').split('\n').forEach(function (l) { d.appendChild(h('div', 'caixa', [rico(l)])); });
    return d;
  }
  function texto() { return P.texto ? h('div', 'texto', [rico(P.texto)]) : null; }
  function cta() { return P.cta ? h('div', 'cta', [P.cta]) : null; }
  function lista(itens) {
    var ul = h('ul', 'lista');
    itens.forEach(function (p) { ul.appendChild(h('li', null, [icone('check'), h('span', null, [rico(p.texto || p)])])); });
    return ul;
  }
  // Pontos com número/destaque ("3", "24h", "99,9%") viram cartões; sem destaque, viram lista.
  function pontos() {
    var itens = P.pontos || [];
    if (!itens.length) return null;
    if (!itens.some(function (p) { return p && p.destaque; })) return lista(itens);
    var d = h('div', 'pontos');
    itens.forEach(function (p) { d.appendChild(h('div', 'ponto', [h('b', null, [p.destaque || '']), h('span', null, [p.texto || ''])])); });
    return d;
  }
  function ilustracao() {
    if (P.produto) return h('div', 'ilustracao', [imagem(P.produto, 'foto', 'foto do produto')]);
    if (P.icone) return h('div', 'ilustracao', [icone(P.icone)]);
    return null;
  }
  function linhas(itens) {
    var d = h('div', 'linhas');
    itens.forEach(function (it) { if (it[1]) d.appendChild(h('div', 'linha', [icone(it[0]), it[1]])); });
    return d.children.length ? d : null;
  }

  // ---------- montagem ----------
  var modelo = P.modelo;
  if (!TITULO[modelo]) { avisos.push('modelo desconhecido: "' + modelo + '" (use dica, produto, aviso ou evento)'); modelo = 'aviso'; }
  var formato = P.formato || '4:5';
  if (!FORMATOS[formato]) { avisos.push('formato desconhecido: "' + formato + '"'); formato = '4:5'; }
  var etiqueta = P.etiqueta !== undefined ? P.etiqueta : ETIQUETA[modelo];

  var post = h('div', 'post ' + FORMATOS[formato] + ' m-' + modelo);

  if (P.fundo) {
    post.appendChild(imagem(P.fundo, 'fundo', 'fundo'));
    post.appendChild(h('div', 'sombra'));
  } else {
    var fg = h('div', 'fundo-gerado');
    fg.innerHTML = REDE_FUNDO;
    post.appendChild(fg);
  }

  var logo = h('div', 'logo', [imagem(BASE + '../marca/logo-nexus.png', '', 'logótipo'), h('div', 'sub', ['IT SOLUTIONS'])]);
  var parceiroNoTopo = modelo === 'produto' && P.parceiro;
  var direita = parceiroNoTopo ? h('div', 'parceiro', [imagem(P.parceiro, '', 'logótipo do parceiro')])
    : (etiqueta ? h('div', 'etiqueta', [etiqueta]) : null);
  post.appendChild(h('header', null, [logo, direita]));

  var conteudo;
  if (modelo === 'dica') {
    conteudo = [ilustracao(), titulo(P.titulo), texto(), pontos(), cta()];
  } else if (modelo === 'produto') {
    conteudo = [ilustracao(), parceiroNoTopo && etiqueta ? h('div', 'etiqueta', [etiqueta]) : null,
      titulo(P.titulo), texto(), P.pontos && P.pontos.length ? lista(P.pontos) : null, cta()];
  } else if (modelo === 'aviso') {
    if (!P.titulo) avisos.push('falta o título');
    conteudo = [h('h1', 'grande', [rico(P.titulo)]), linhas([['calendario', P.data], ['relogio', P.hora]]), texto()];
  } else { // evento
    var ev = P.evento || {};
    if (!ev.dia || !ev.mes) avisos.push('falta evento.dia ou evento.mes');
    conteudo = [ilustracao(),
      h('div', 'evento-topo', [h('div', 'data-card', [h('div', 'dia', [ev.dia || '?']), h('div', 'mes', [ev.mes || ''])]), titulo(P.titulo)]),
      linhas([['relogio', ev.hora], ['local', ev.local]]), texto(), cta()];
  }
  post.appendChild(h('main', null, conteudo));
  post.appendChild(h('footer', null, [h('span', 'tema', [P.rodape || '']), h('span', 'site', [P.site || 'nexus-solutions.pt'])]));
  document.body.appendChild(post);

  // ---------- ajustar o texto ao espaço ----------
  var t0 = TITULO[modelo] * ESCALA[formato];
  var t = t0;
  function aplica() {
    post.style.setProperty('--t', t + 'px');
    post.style.setProperty('--x', Math.max(28, 36 * Math.max(0.8, t / t0)) + 'px');
  }
  // inteiro = cada linha do título fica numa só linha (sem partir a meio).
  function cabe(inteiro) {
    var main = post.querySelector('main');
    if (main.scrollHeight > main.clientHeight + 1) return false;
    // O conteúdo está alinhado em baixo: quando não cabe, sai por cima (o scrollHeight não o apanha).
    var m = main.getBoundingClientRect();
    var est = getComputedStyle(main);
    var cima = m.top + parseFloat(est.paddingTop) - 1;
    var baixo = m.bottom - parseFloat(est.paddingBottom) + 1;
    for (var k = 0; k < main.children.length; k++) {
      var c = main.children[k].getBoundingClientRect();
      if (c.top < cima || c.bottom > baixo) return false;
    }
    var il = post.querySelector('.ilustracao');
    if (il && il.clientHeight < 200) return false;
    var largos = post.querySelectorAll('.caixa, .grande, .ponto, .linha, .lista li');
    for (var i = 0; i < largos.length; i++) if (largos[i].scrollWidth > largos[i].clientWidth + 1) return false;
    if (inteiro) {
      var caixas = post.querySelectorAll('.caixa');
      for (var j = 0; j < caixas.length; j++) if (caixas[j].clientHeight > t * 1.06 * 1.5 + 8) return false;
    }
    return true;
  }
  function encolhe(inteiro, minimo) {
    t = t0;
    aplica();
    while (!cabe(inteiro) && t > t0 * minimo) {
      t -= 2;
      aplica();
    }
    return cabe(inteiro);
  }
  function ajusta() {
    // 1.º tenta sem partir as linhas do título; 2.º sem o ícone decorativo; por fim deixa partir.
    var ok = encolhe(true, 0.72);
    var il = post.querySelector('.ilustracao');
    if (!ok && il && !il.querySelector('.foto')) {
      il.remove();
      avisos.push('o ícone saiu por falta de espaço');
      ok = encolhe(true, 0.72);
    }
    if (!ok) {
      ok = encolhe(false, 0.6);
      if (ok) avisos.push('o título só coube partido em mais linhas: considere encurtá-lo');
    }
    if (!ok) avisos.push('o texto não cabe: encurte o título, o texto ou os pontos');
    document.body.setAttribute('data-avisos', avisos.join(' | '));
    document.body.setAttribute('data-estado', 'pronto');
    // Lido pelo scripts/gerar-post.ps1 no registo do Edge.
    console.log('NEXUS-ESTADO:pronto');
    avisos.forEach(function (a) { console.log('NEXUS-AVISO:' + a); });
    // Dentro do editor (modelos/editor), mostra os avisos ao lado da pré-visualização.
    if (window.parent !== window) window.parent.postMessage({ nexus: 'pronto', avisos: avisos }, '*');
  }

  window.addEventListener('load', function () {
    (document.fonts ? document.fonts.ready : Promise.resolve()).then(ajusta);
  });
})();
