# NEXUS – Redes Sociais com IA

Sistema que cria posts para as redes sociais da NEXUS IT Solutions (Instagram, Facebook e, mais tarde, LinkedIn).
**Nenhum post é publicado sem aprovação de uma pessoa.**

## Estrutura

```
painel-laravel/    Painel (Laravel 13): pedir, criar, editar, aprovar e agendar posts; IA automática;
                   biblioteca de imagens; calendário. Os modelos dos posts estão em public/modelos/.
modelos/           Guia dos modelos (LEIA-ME.md) e exemplos de cada tipo de post
scripts/
  gerar-post.ps1   Gera um post (post.html + post.png) a partir de um dados.json, com os modelos
posts/             Posts feitos fora do painel (os primeiros posts de teste estão aqui)
marca/             Logótipo NEXUS (tirado do site; falta a versão em alta resolução)
painel/            Protótipo inicial do painel (formato do canvas Design do claude.ai), só para referência
```

## Painel

- Servidor (rede interna): http://192.168.1.69:8091
- Neste computador: `cd painel-laravel; php artisan serve` → http://localhost:8000
- Testes: `cd painel-laravel; php artisan test`
- Como atualizar o servidor e restantes detalhes: ver `CLAUDE.md`.

## Gerar um post pelo terminal

```powershell
.\scripts\gerar-post.ps1 -Pasta posts\2026-11-workstations
```

A pasta tem um `dados.json` (campos em `modelos/LEIA-ME.md`); o script cria `post.html` e `post.png`
e avisa se o texto não couber ou faltar uma imagem.
