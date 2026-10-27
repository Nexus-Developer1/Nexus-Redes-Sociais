# NEXUS – Redes Sociais com IA

Protótipo de um sistema que cria posts para as redes sociais da NEXUS IT Solutions
(Instagram, Facebook e, mais tarde, LinkedIn). **Nenhum post é publicado sem aprovação de uma pessoa.**

## Estrutura

```
posts/
  aviso-fecho/     Post "Amanhã estamos fechados" (1080x1080), 100% feito em código
  dica-backup/     Post "O seu backup está mesmo seguro?" (1080x1350)
                   fundo gerado por IA (Pollinations, gratuito) + montagem em código
painel/            Protótipo do painel de aprovação (formato do canvas Design do claude.ai)
marca/             Logótipo NEXUS (tirado do site; falta a versão em alta resolução)
scripts/
  render.ps1       Converte um post.html em PNG usando o Microsoft Edge
```

## Como funciona cada post

1. `post.html` define o design (HTML + CSS + SVG): texto, cores, logótipo, layout.
2. O `scripts/render.ps1` abre esse HTML no Edge, sem janela, e guarda uma imagem PNG.

Para gerar de novo a imagem depois de alterar o HTML:

```powershell
.\scripts\render.ps1 -Post posts\dica-backup -Largura 1080 -Altura 1350
.\scripts\render.ps1 -Post posts\aviso-fecho -Largura 1080 -Altura 1080
```

## Painel de aprovação

O ficheiro `painel/Main.dc.html` está no formato do canvas Design do claude.ai
(precisa do runtime desse editor e não abre diretamente no navegador).
Versão online: https://claude.ai/artifact/V5St7mNn398ZmY99MaAYgc

## Próximos passos

- Logótipo NEXUS em alta resolução (PNG transparente)
- IA de imagens paga (OpenAI ou Google) para fundos com mais qualidade
- Modelos fixos por tipo de post (aviso, dica, produto, evento)
- Ligação à API da Meta (Instagram + Facebook) e fluxo de aprovação real
