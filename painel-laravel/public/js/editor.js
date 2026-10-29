/* Editor de posts do painel: campos → pré-visualização ao vivo (public/modelos/modelos.js) → o servidor gera o PNG. */
(function () {
  'use strict';

  var E = window.EDITOR || {};
  var $ = function (s, el) { return (el || document).querySelector(s); };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };
  var form = $('#form-post');
  var ALTURA = { '4:5': 1350, '1:1': 1080, '9:16': 1920 };
  var alterado = false;

  // ---------- contador da legenda (limite do Instagram: 2200) ----------
  var legenda = $('#legenda');
  function contaLegenda() { $('#conta-legenda').textContent = '(' + legenda.value.length + ' / 2200)'; }
  if (legenda) { legenda.addEventListener('input', contaLegenda); contaLegenda(); }

  form.addEventListener('input', function () { alterado = true; });
  window.addEventListener('beforeunload', function (e) { if (alterado) { e.preventDefault(); e.returnValue = ''; } });
  form.addEventListener('submit', function () {
    alterado = false;
    if (E.design) $('#design-json').value = JSON.stringify(ler());
    var b = $('#btn-guardar');
    b.disabled = true;
    b.textContent = E.design ? 'A gerar a imagem…' : 'A guardar…';
  });

  if (!E.design) return; // post sem modelo: só legenda, data e redes

  var imagens = { fundo: E.design.fundo || '', produto: E.design.produto || '', parceiro: E.design.parceiro || '' };

  // ---------- preencher ----------
  $$('[data-campo]').forEach(function (el) {
    var v = E.design[el.dataset.campo];
    el.value = v === undefined || v === null ? '' : v;
  });
  if (!E.design.formato) $('#f-formato').value = '4:5';
  var ev = E.design.evento || {};
  $$('[data-evento]').forEach(function (el) { el.value = ev[el.dataset.evento] || ''; });
  $('#f-pontos').value = (E.design.pontos || []).map(function (p) {
    return typeof p === 'string' ? p : (p.destaque ? p.destaque + ' | ' : '') + (p.texto || '');
  }).join('\n');

  function mostrarCampos() {
    var modelo = $('#f-modelo').value;
    $$('[data-modelos]').forEach(function (el) { el.hidden = el.dataset.modelos.split(' ').indexOf(modelo) < 0; });
  }
  function mostrarImagens() {
    $$('[data-imagem]').forEach(function (bloco) {
      var nome = imagens[bloco.dataset.imagem] || '';
      $('.nome', bloco).textContent = nome;
      $('.tirar', bloco).hidden = !nome;
    });
  }
  mostrarCampos();
  mostrarImagens();

  // ---------- ler os campos ----------
  function visivel(el) { return !el.closest('[hidden]'); }
  function ler() {
    var modelo = $('#f-modelo').value;
    var d = { modelo: modelo, formato: $('#f-formato').value };
    $$('#design [data-campo]').forEach(function (el) {
      var k = el.dataset.campo;
      if (k === 'modelo' || k === 'formato' || !visivel(el)) return;
      var v = el.value.replace(/\r/g, '').trim();
      if (v) d[k] = v;
    });
    if (visivel($('#f-pontos'))) {
      var pontos = $('#f-pontos').value.split('\n').map(function (l) { return l.trim(); }).filter(Boolean).map(function (l) {
        var i = l.indexOf('|');
        return i >= 0 ? { destaque: l.slice(0, i).trim(), texto: l.slice(i + 1).trim() } : l;
      });
      if (pontos.length) d.pontos = pontos;
    }
    if (modelo === 'evento') {
      var e = {};
      $$('[data-evento]').forEach(function (el) { if (el.value.trim()) e[el.dataset.evento] = el.value.trim(); });
      d.evento = e;
    }
    $$('[data-imagem]').forEach(function (bloco) {
      var k = bloco.dataset.imagem;
      if (imagens[k] && !bloco.hidden) d[k] = imagens[k];
    });
    return d;
  }

  // ---------- pré-visualização ----------
  var temporizador = null;
  var formato = '4:5';

  // O post (1080 px de largura) é encolhido para caber no espaço livre, sem obrigar a fazer scroll.
  function ajustaEscala() {
    var area = $('#area-previa');
    var altura = ALTURA[formato] || 1350;
    var estreito = window.matchMedia('(max-width: 960px)').matches;
    var w = estreito ? Math.min(area.clientWidth, 520) : area.clientWidth;
    var h = estreito ? window.innerHeight * 0.75 : area.clientHeight;
    var escala = Math.min(w / 1080, h / altura);
    if (!(escala > 0)) escala = 0.4;
    var f = $('#previa');
    f.style.height = altura + 'px';
    f.style.transform = 'scale(' + escala + ')';
    $('#moldura').style.width = Math.floor(1080 * escala) + 'px';
    $('#moldura').style.height = Math.floor(altura * escala) + 'px';
  }
  if (window.ResizeObserver) new ResizeObserver(ajustaEscala).observe($('#area-previa'));
  window.addEventListener('resize', ajustaEscala);

  function previa() {
    var d = ler();
    formato = d.formato;
    var json = JSON.stringify(d).replace(/</g, '\\u003c');
    var html = '<!doctype html><html lang="pt"><head><meta charset="utf-8">'
      + '<base href="' + E.pasta + '">'
      + '<link href="' + E.modelos + 'modelos.css" rel="stylesheet"></head><body>'
      + '<script>window.POST = ' + json + ';<\/script><script src="' + E.modelos + 'modelos.js"><\/script></body></html>';
    ajustaEscala();
    $('#previa').srcdoc = html;
  }
  function previaDepois() { clearTimeout(temporizador); temporizador = setTimeout(previa, 350); }

  window.addEventListener('message', function (e) {
    if (e.source !== $('#previa').contentWindow || !e.data || e.data.nexus !== 'pronto') return;
    var caixa = $('#avisos-vivo');
    caixa.innerHTML = '';
    var lista = e.data.avisos || [];
    (lista.length ? lista : ['Tudo certo: o texto cabe.']).forEach(function (a) {
      var p = document.createElement('div');
      p.className = lista.length ? 'aviso amarelo' : 'aviso';
      p.textContent = (lista.length ? '⚠ ' : '✓ ') + a;
      caixa.appendChild(p);
    });
  });

  $('#design').addEventListener('input', function (e) {
    if (e.target.id === 'f-modelo') mostrarCampos();
    previaDepois();
  });

  // ---------- enviar imagens ----------
  $$('[data-imagem]').forEach(function (bloco) {
    var k = bloco.dataset.imagem;
    var input = $('input[type=file]', bloco);
    input.addEventListener('change', function () {
      if (!input.files[0]) return;
      var fd = new FormData();
      fd.append('ficheiro', input.files[0]);
      $('.nome', bloco).textContent = 'a enviar…';
      fetch(E.upload, { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': E.csrf, 'Accept': 'application/json' } })
        .then(function (r) {
          return r.json().catch(function () { return {}; }).then(function (j) {
            if (!r.ok) throw new Error(j.message || ('Erro ' + r.status));
            return j;
          });
        })
        .then(function (j) { imagens[k] = j.nome; alterado = true; })
        .catch(function (err) { alert('A imagem não foi enviada: ' + err.message); })
        .then(function () { input.value = ''; mostrarImagens(); previa(); });
    });
    $('.tirar', bloco).addEventListener('click', function () { imagens[k] = ''; alterado = true; mostrarImagens(); previa(); });
  });

  previa();
})();
