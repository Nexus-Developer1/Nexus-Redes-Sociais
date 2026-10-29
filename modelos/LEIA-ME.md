# Modelos de posts NEXUS

Sete modelos: **dica**, **produto**, **aviso**, **evento** e, no estilo do feed da NEXUS, **numero** (número gigante + faixa verde), **frase** (frase ao centro, foto verde) e **montra** (produto em destaque, "Powered by"). Qualquer um pode levar uma **foto de fundo** (equipa, instalação, produto ou IA de imagens), com tom verde por cima.
Exemplos prontos em `exemplos/` (com o `post.png` de cada um).

## Onde estão as coisas
- Os modelos (`modelos.css`, `modelos.js`, fontes Rajdhani e logótipo) estão em **`painel-laravel/public/modelos/`**:
  é a mesma cópia usada pelo painel e pelo script do terminal.
- Dados iniciais de cada tipo (quando se cria um post novo no painel): `painel-laravel/resources/modelos/<tipo>.json`.
- Esta pasta (`modelos/`) só tem este guia e os `exemplos/`.

## Como fazer um post no painel (sem terminal)
No painel, **+ Criar post** → escolher o tipo → página de edição com legenda, data, redes e a imagem,
com pré-visualização ao vivo. **Guardar e gerar imagem** cria o PNG no servidor; **Enviar para aprovação** põe-no na fila.
Cada post da fila tem também o botão **Editar**.

## Como fazer um post (terminal, para o Claude)
1. Criar uma pasta, por exemplo `posts/2026-11-workstations/`, com um `dados.json` (copiar de um exemplo).
2. Pôr na mesma pasta as imagens que o post usa (fundo, foto do produto, logótipo do parceiro).
3. Correr:
   ```powershell
   .\scripts\gerar-post.ps1 -Pasta posts\2026-11-workstations
   ```
   O script cria `post.html` e `post.png`. Se aparecer `WARNING`, o post tem um problema
   (texto que não cabe, imagem que não existe, etc.) e o script termina com o código 2.
4. Enviar para aprovação com `php artisan post:submeter --imagem=...\post.png ...`.

Mudar o aspeto de todos os posts = mudar `painel-laravel/public/modelos/modelos.css` / `modelos.js`. O `post.html` é gerado; não se edita à mão.

## Campos do dados.json
| Campo | Modelos | O que é |
|---|---|---|
| `modelo` | todos | `dica`, `produto`, `aviso`, `evento`, `numero`, `frase` ou `montra` |
| `numero` | numero | só o algarismo (ex.: `5`); o título vai sem o número |
| `foto` | todos | foto de fundo (equipa, instalação, produto, IA de imagens); `fundo` também funciona |
| `enquadramento` | todos | com foto: `centro`, `topo` ou `baixo` (para não cortar caras) |
| `tom` | todos | com foto: tom verde `suave` (por defeito), `forte` ou `nenhum` |
| `formato` | todos | `4:5` (feed, por defeito), `1:1` (quadrado) ou `9:16` (Stories/Reels) |
| `titulo` | todos | `\n` muda de linha; `*palavra*` fica a verde |
| `texto` | todos | frase por baixo do título (aceita `\n` e `*...*`) |
| `etiqueta` | todos | etiqueta no canto (por defeito: Dica / Aviso / Evento; `""` para nenhuma) |
| `rodape` | todos | texto à esquerda no rodapé (tema do post) |
| `site` | todos | à direita no rodapé (por defeito `nexus-solutions.pt`) |
| `fundo` | todos | imagem de fundo (IA de imagens). Sem fundo, usa o fundo verde feito em código |
| `icone` | dica, produto, evento | `escudo`, `servidor`, `nuvem`, `rede`, `energia`, `monitor`, `chip` |
| `pontos` | dica, produto | dica: `[{"destaque":"3","texto":"cópias"}]` = cartões; só texto = lista com ✓ |
| `cta` | dica, produto, evento | botão verde, ex.: `"Peça a sua proposta"` |
| `produto` | produto | foto **oficial** do produto (PNG com fundo transparente fica melhor) |
| `parceiro` | produto | logótipo **oficial** do parceiro (Supermicro, NVIDIA…), aparece no topo |
| `data`, `hora` | aviso | linhas com ícone de calendário e relógio |
| `evento` | evento | `{"dia":"12","mes":"Nov","hora":"14h30 – 17h00","local":"Porto"}` |

## Regras
- O texto é sempre posto em código; logótipos e fotos de produtos vêm de ficheiros oficiais, nunca de IA.
- O título encolhe sozinho até caber. Se for longo demais, o ícone sai primeiro e depois o script avisa.
- O logótipo vem de `painel-laravel/public/modelos/logo-nexus.png` (cópia de `marca/logo-nexus.png`): quando houver a versão em alta resolução, substituir os dois.
