/* Editor de posts NEXUS: formulário → pré-visualização ao vivo (modelos.js) → imagem final (gerar-post.ps1). */
(function () {
  'use strict';

  var $ = function (s, el) { return (el || document).querySelector(s); };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); };
  var ALTURA = { '4:5': 1350, '1:1': 1080, '9:16': 1920 };

  var pasta = null;     // post aberto
  var imagens = {};     // fundo / produto / parceiro -> nome do ficheiro
  var outros = {};      // campos do dados.json que o formulário não mostra (ex.: site), para não se perderem
  var temporizador = null;

  function api(url, opcoes) {
    return fetch(url, opcoes).then(function (r) {
      return r.json().catch(function () { return { erro: 'Resposta inválida do servidor.' }; }).then(function (j) {
        if (!r.ok) throw new Error(j.erro || ('Erro ' + r.status));
        return j;
      });
    });
  }
  function postJson(url, dados) {
    return api(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(dados) });
  }

  // ---------- lista de posts ----------
  function carregarLista(selecionar) {
    return api('/api/posts').then(function (j) {
      var sel = $('#lista');
      sel.innerHTML = '<option value="">—</option>';
      j.posts.forEach(function (p) {
        var o = document.createElement('option');
        o.value = p.pasta;
        o.textContent = p.pasta + '  ·  ' + p.modelo + (p.titulo ? '  ·  ' + p.titulo : '');
        sel.appendChild(o);
      });
      if (selecionar) sel.value = selecionar;
    });
  }
  $('#lista').addEventListener('change', function () { if (this.value) abrir(this.value); });

  // ---------- novo post ----------
  function hoje() { return new Date().toISOString().slice(0, 10); }
  $('#btn-novo').addEventListener('click', function () {
    $('#novo-pasta').value = hoje() + '-' + $('#novo-modelo').value;
    $('#novo-erro').hidden = true;
    $('#dlg-novo').showModal();
    $('#novo-pasta').select();
  });
  $('#novo-modelo').addEventListener('change', function () {
    var campo = $('#novo-pasta');
    if (/^\d{4}-\d{2}-\d{2}-(dica|produto|aviso|evento)$/.test(campo.value)) campo.value = hoje() + '-' + this.value;
  });
  $('#form-novo').addEventListener('submit', function (e) {
    if (!e.submitter || e.submitter.value !== 'criar') return; // Cancelar fecha
    e.preventDefault();
    var nome = $('#novo-pasta').value.trim();
    postJson('/api/novo', { pasta: nome, modelo: $('#novo-modelo').value })
      .then(function (j) { $('#dlg-novo').close(); return carregarLista(j.pasta).then(function () { return abrir(j.pasta); }); })
      .catch(function (err) { $('#novo-erro').textContent = err.message; $('#novo-erro').hidden = false; });
  });

  // ---------- abrir / preencher ----------
  function abrir(nome) {
    return fetch('/posts/' + encodeURIComponent(nome) + '/dados.json', { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('Não consegui abrir o post.'); return r.json(); })
      .then(function (d) {
        pasta = nome;
        preencher(d);
        $('#vazio').hidden = true;
        $('#editor').hidden = false;
        $('#nome-pasta').textContent = 'posts\\' + nome;
        $('#resultado').hidden = true;
        $('#estado').textContent = '';
        $('#lista').value = nome;
        previa();
      })
      .catch(function (err) { alert(err.message); });
  }

  function preencher(d) {
    outros = {};
    Object.keys(d).forEach(function (k) {
      if (!['modelo', 'formato', 'titulo', 'texto', 'pontos', 'data', 'hora', 'evento', 'icone', 'cta', 'etiqueta', 'rodape', 'fundo', 'produto', 'parceiro'].includes(k)) outros[k] = d[k];
    });
    $$('[data-campo]').forEach(function (el) {
      var v = d[el.dataset.campo];
      el.value = v === undefined || v === null ? '' : v;
    });
    if (!d.formato) $('#f-formato').value = '4:5';
    var ev = d.evento || {};
    $$('[data-evento]').forEach(function (el) { el.value = ev[el.dataset.evento] || ''; });
    $('#f-pontos').value = (d.pontos || []).map(function (p) {
      if (typeof p === 'string') return p;
      return (p.destaque ? p.destaque + ' | ' : '') + (p.texto || '');
    }).join('\n');
    imagens = { fundo: d.fundo || '', produto: d.produto || '', parceiro: d.parceiro || '' };
    mostrarImagens();
    mostrarCampos();
  }

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

  // ---------- ler o formulário ----------
  function ler() {
    var modelo = $('#f-modelo').value;
    var visivel = function (el) { return !el.closest('[hidden]'); };
    var d = { modelo: modelo, formato: $('#f-formato').value };
    $$('[data-campo]').forEach(function (el) {
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
      var ev = {};
      $$('[data-evento]').forEach(function (el) { if (el.value.trim()) ev[el.dataset.evento] = el.value.trim(); });
      d.evento = ev;
    }
    $$('[data-imagem]').forEach(function (bloco) {
      var k = bloco.dataset.imagem;
      if (imagens[k] && !bloco.hidden) d[k] = imagens[k];
    });
    Object.keys(outros).forEach(function (k) { if (!(k in d)) d[k] = outros[k]; });
    return d;
  }

  // ---------- pré-visualização ----------
  function previa() {
    var d = ler();
    var json = JSON.stringify(d).replace(/</g, '\\u003c');
    var html = '<!doctype html><html lang="pt"><head><meta charset="utf-8">'
      + '<base href="/posts/' + encodeURIComponent(pasta) + '/">'
      + '<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&display=block" rel="stylesheet">'
      + '<link href="/modelos/modelos.css" rel="stylesheet"></head><body>'
      + '<script>window.POST = ' + json + ';<\/script><script src="/modelos/modelos.js"><\/script></body></html>';
    var altura = ALTURA[d.formato] || 1350;
    var largura = Math.min(540, $('.lado').clientWidth || 540);
    var escala = largura / 1080;
    var f = $('#previa');
    f.style.height = altura + 'px';
    f.style.transform = 'scale(' + escala + ')';
    $('#moldura').style.width = largura + 'px';
    $('#moldura').style.height = Math.round(altura * escala) + 'px';
    $('#avisos').innerHTML = '';
    f.srcdoc = html;
  }
  function previaDepois() {
    clearTimeout(temporizador);
    temporizador = setTimeout(previa, 350);
  }
  window.addEventListener('message', function (e) {
    if (e.source !== $('#previa').contentWindow || !e.data || e.data.nexus !== 'pronto') return;
    mostrarAvisos(e.data.avisos);
  });
  function mostrarAvisos(lista) {
    var caixa = $('#avisos');
    caixa.innerHTML = '';
    (lista.length ? lista : ['Tudo certo: o texto cabe.']).forEach(function (a) {
      var p = document.createElement('p');
      p.textContent = lista.length ? '⚠ ' + a : '✓ ' + a;
      if (!lista.length) p.className = 'ok';
      caixa.appendChild(p);
    });
  }

  $('#form').addEventListener('input', function (e) {
    if (e.target.id === 'f-modelo') mostrarCampos();
    previaDepois();
  });
  window.addEventListener('resize', previaDepois);

  // ---------- imagens ----------
  $$('[data-imagem]').forEach(function (bloco) {
    var k = bloco.dataset.imagem;
    $('input[type=file]', bloco).addEventListener('change', function () {
      if (!this.files[0]) return;
      var fd = new FormData();
      fd.append('pasta', pasta);
      fd.append('ficheiro', this.files[0]);
      var input = this;
      $('.nome', bloco).textContent = 'a enviar…';
      api('/api/imagem', { method: 'POST', body: fd })
        .then(function (j) { imagens[k] = j.nome; })
        .catch(function (err) { alert(err.message); })
        .then(function () { input.value = ''; mostrarImagens(); previa(); });
    });
    $('.tirar', bloco).addEventListener('click', function () { imagens[k] = ''; mostrarImagens(); previa(); });
  });

  // ---------- guardar e gerar ----------
  $('#btn-gerar').addEventListener('click', function () {
    var botao = this;
    botao.disabled = true;
    $('#estado').textContent = 'A gerar a imagem (uns 10 segundos)…';
    postJson('/api/gerar', { pasta: pasta, dados: ler() })
      .then(function (j) {
        $('#estado').textContent = j.ok ? 'Imagem gerada.' : 'Imagem gerada, mas com avisos.';
        if (j.avisos.length) mostrarAvisos(j.avisos);
        $('#caminho').textContent = j.caminho;
        $('#pasta-pedido').textContent = pasta;
        $('#img-png').src = j.png;
        $('#link-png').href = j.png;
        $('#resultado').hidden = false;
        carregarLista(pasta);
      })
      .catch(function (err) { $('#estado').textContent = ''; alert(err.message); })
      .then(function () { botao.disabled = false; });
  });

  // ?post=<pasta> abre logo esse post
  var inicial = new URLSearchParams(location.search).get('post');
  carregarLista(inicial).then(function () { if (inicial) abrir(inicial); });
})();
