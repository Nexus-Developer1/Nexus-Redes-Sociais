# Modelos de posts NEXUS

Quatro modelos fixos: **dica**, **produto**, **aviso** e **evento**. Todos têm o mesmo topo (logótipo NEXUS) e o mesmo rodapé.
Exemplos prontos em `exemplos/` (com o `post.png` de cada um).

## Como fazer um post (sem terminal): editor
Duplo clique em **`abrir-editor.bat`** (raiz do projeto). Abre http://127.0.0.1:8020 no browser:
- **+ Novo post**: escolhe o tipo e o nome da pasta (fica em `posts\`), começa a partir do exemplo do modelo;
- à esquerda os campos, à direita a pré-visualização ao vivo e os avisos (texto que não cabe, imagem em falta);
- **Escolher…** envia fundo, foto do produto ou logótipo do parceiro para a pasta do post;
- **Guardar e gerar imagem** grava o `dados.json` e cria o `post.png` (é o mesmo script do terminal).
Fechar a janela preta do `abrir-editor.bat` desliga o editor. Só funciona neste computador (127.0.0.1).

## Como fazer um post (terminal)
1. Criar uma pasta, por exemplo `posts/2026-11-workstations/`, com um `dados.json` (copiar de um exemplo).
2. Pôr na mesma pasta as imagens que o post usa (fundo, foto do produto, logótipo do parceiro).
3. Correr:
   ```powershell
   .\scripts\gerar-post.ps1 -Pasta posts\2026-11-workstations
   ```
   O script cria `post.html` e `post.png`. Se aparecer `WARNING`, o post tem um problema
   (texto que não cabe, imagem que não existe, etc.) e o script termina com o código 2.
4. Enviar para aprovação com `php artisan post:submeter --imagem=...\post.png ...`.

Mudar o aspeto de todos os posts = mudar `modelos.css` / `modelos.js`. O `post.html` é gerado; não se edita à mão.

## Campos do dados.json
| Campo | Modelos | O que é |
|---|---|---|
| `modelo` | todos | `dica`, `produto`, `aviso` ou `evento` |
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
- O logótipo vem de `marca/logo-nexus.png`: quando houver a versão em alta resolução, basta substituir esse ficheiro.
