# Contexto do projeto: NEXUS – Redes Sociais com IA

Responder sempre em **português de Portugal**, de forma simples e direta.
O utilizador preocupa-se com o consumo dos limites do plano: evitar pesquisas web e trabalho desnecessários.

## Objetivo
Sistema que cria posts para as redes sociais da NEXUS de forma autónoma.
**Nenhum post é publicado sem aprovação de uma pessoa.**
- Redes: Instagram + Facebook primeiro (API da Meta). LinkedIn depois, mas é importante (clientes B2B).
- O Instagram tem cerca de 200 seguidores e a empresa quer crescer.

## A empresa
- **NEXUS Solutions / NEXUS IT Solutions**: empresa portuguesa de TI, fundada em 2010. Site: https://nexus-solutions.pt (nxs.pt redireciona para lá).
- Produtos e serviços: PCs, mini-PCs, POS, workstations (3D, CAD, render), servidores e storage, NVIDIA/GPU, Supermicro,
  alta disponibilidade, backup e disaster recovery, cibersegurança, videoconferência, datacenters, redes, UPS Riello,
  bastidores, consultoria e suporte, programa de revendedores.
- Redes: instagram.com/nexus.solutions, facebook.com/nexusolutions, linkedin.com/company/nexussolutions, YouTube.
- Contacto público: info@nexus-solutions.pt, 229 437 980.

## Marca e estilo dos posts
- Cor dominante: **verde** (no site aparecem #61a229, #00b147). Os posts atuais usam verde néon sobre fundos escuros.
- Logótipo: wordmark "NEXUS" (+ "IT SOLUTIONS"). Só temos a versão do site, de 150x28 px (`marca/logo-nexus.png`); **falta a versão em alta resolução**.
- Estilo de referência dos posts atuais: fundo de datacenter futurista verde (gerado por IA), fotos oficiais de produtos,
  títulos em caixas verde-escuras, letra técnica (usámos Rajdhani), logótipo do parceiro (ex.: Supermicro).
- Formato preferido: 1080x1350 (4:5) para o feed; 1080x1080 também serve.

## Como os posts são feitos (decidido)
- O Claude não gera imagens. Fundos realistas vêm de uma **IA de imagens externa**; o Claude escreve o texto e monta o post em HTML/CSS/SVG.
- O HTML é convertido em PNG com um browser sem janela: no painel, `PostRenderer` (servidor); no terminal, `scripts/gerar-post.ps1` (Edge).
- Texto, logótipos e fotos de produtos **nunca** são gerados por IA: o texto é posto em código; logótipos e fotos de produtos vêm de ficheiros oficiais.
- Para testes usámos o Pollinations (gratuito, sem chave): https://image.pollinations.ai/prompt/<texto>?width=1080&height=1350&nologo=true
  Limitações: 1 imagem a cada 45 s, baixa resolução (~686x858), marca de água no canto inferior direito, ignora detalhes do pedido.
  Para produção: API paga (OpenAI ou Google), poucos cêntimos por imagem.

## Ficheiros
- **Modelos fixos** (dica, produto, aviso, evento) em HTML/CSS/JS, formatos 4:5, 1:1 e 9:16: código em `painel-laravel/public/modelos/`, guia e exemplos em `modelos/` (ver `modelos/LEIA-ME.md`).
  Cada post novo = pasta com `dados.json` → `.\scripts\gerar-post.ps1 -Pasta <pasta>` cria `post.html` + `post.png`
  e avisa (código 2) se o texto não couber ou faltar uma imagem. Usar sempre os modelos para posts novos.
  **Editor no painel** (para a equipa): "+ Criar post" e "Editar" → `PostEditorController`; a imagem é gerada no servidor pelo
  `App\Services\PostRenderer` (chrome-headless-shell em `/opt/chrome-headless-shell`; no Windows usa o Edge). Dados do modelo em `posts.design`,
  imagens em `public/imagens-posts/design/<id>/`.
- `posts/aviso-fecho/`: aviso "Amanhã estamos fechados" (23 out 2026), 100% em código.
- `posts/dica-backup/`: dica da regra 3-2-1 de backup, com fundo do Pollinations.
- `painel/`: protótipo do painel de aprovação (formato do canvas Design do claude.ai). Online: https://claude.ai/artifact/V5St7mNn398ZmY99MaAYgc
  Contém a fila de posts para aprovar (Aprovar e agendar / Pedir alteração / Rejeitar), os próximos posts e uma caixa "Pedir um post".
- `painel-laravel/`: **painel de aprovação a sério**, em Laravel 13 + SQLite (PHP 8.3 e Composer em `C:\Users\dev01\tools\php83`).
  Sem Vite/npm: CSS simples em `public/css/painel.css`. Login obrigatório; cada decisão fica no histórico (`post_reviews`) com quem e quando.
  Arrancar: `cd painel-laravel; php artisan serve` → http://localhost:8000. Testes: `php artisan test`.
  Estados do post: idea, creating, pending, revising, approved, rejected, published.
  Comandos para o Claude:
  - `php artisan post:pendentes` (ou `--json`): pedidos novos, alterações pedidas e ideias por criar.
  - `php artisan post:submeter --titulo= --imagem= --legenda-ficheiro= --redes=Instagram,Facebook --quando="AAAA-MM-DD HH:MM" [--post=ID] [--pedido=ID]`:
    põe o post na fila como "À espera de aprovação". **Nunca aprova nem publica**; recusa alterar posts já aprovados.
  - `php artisan post:ideia --titulo= --quando= --tipo= [--post=ID]`: põe uma ideia no calendário (o painel permite "Trocar tema" e "Descartar").
  - `php artisan post:publicar [--simular]`: publica na Meta os posts aprovados cuja hora chegou (cron a cada 5 min no servidor). Sem `META_PAGE_TOKEN` no `.env` não publica nada.
  - `php artisan post:tratar-pedidos` (cron a cada minuto): a **IA automática** cria os posts de "Pedir um post" e refaz os que têm
    "Pedir alteração" (só posts com modelo; o pedido pode trazer a data de publicação, que a IA não muda), gera a imagem com os modelos e põe-nos "À espera de aprovação". Nunca aprova nem publica.
    Fornecedor com API formato OpenAI em `config/ia.php` (`IA_ENDERECO`, `IA_CHAVE`, `IA_MODELO`=qwen/qwen3.8-27b, o melhor em PT-PT); para testes: **Groq** (grátis),
    conta própria da NEXUS (não usar a chave da conta partilhada da advocacia). Sem `IA_CHAVE` não faz nada. Produção: talvez API do Claude (~1 €/mês).
  - `php artisan post:calendario-ia` (cron a cada minuto): **calendário automático**. A IA troca o tema das ideias com "Trocar tema",
    faz cada ideia sozinha `IA_IDEIAS_DIAS_ANTES` (7) dias antes da data e, de segunda a sexta, propõe uma vez por semana as ideias da semana
    seguinte até `IA_IDEIAS_POR_SEMANA` (3), a pensar nos feriados e dias especiais (`App\Services\DatasEspeciais`). Botão "✨ Propor ideias" no calendário
    (ou `--propor`). 0 em qualquer das duas desliga essa parte. Ideias cujo pedido falhou não se repetem sozinhas (fica o botão).
  - **Carrossel**: `design.carrossel` + `design.slides` (até 8: destaque, título, texto) + `design.fecho`. Capa = o modelo normal com "Deslize ›",
    depois um slide por ponto e no fim os contactos (automático). O `PostRenderer` gera uma imagem por slide (3 browsers de cada vez);
    a capa fica em `image_path` e todas em `posts.imagens_carrossel`. **A IA nunca decide sozinha**: quem pede escolhe "Imagem única"
    ou "Carrossel" em "Pedir um post" (`post_requests.carrossel`) e em "+ Criar post"; ideias automáticas = imagem única;
    "Pedir alteração" só passa a carrossel se o pedido o disser. No editor é o visto "Carrossel".
    Publicação: carrossel nativo no Instagram e post com várias fotos no Facebook. Só feed (4:5 ou 1:1), não Stories.
  - **Stories**: rede "Stories" (Instagram). Com ela marcada, o `PostRenderer` gera também a capa em 9:16 (`posts.imagem_story`);
    os posts novos da IA e do painel com Instagram levam-na por defeito. Publica com `media_type=STORIES` (`instagram_story_id`).
    No editor: botões "Feed | Stories" na pré-visualização; no cartão, a etiqueta "Stories ↗" abre a versão 9:16.
  - **Biblioteca da NEXUS** (`/biblioteca`, tabela `imagens_biblioteca`, ficheiros em `public/imagens-posts/biblioteca/`): fotos próprias, produtos e logótipos; a IA usa primeiro estas (fotos de fundo e "montra" com produto + parceiro).
  - Fotos de fundo grátis: **Pixabay** (`PIXABAY_CHAVE`; o Pexels suspendeu chaves novas, mas `PEXELS_CHAVE` também funciona e tem prioridade): botão "Procurar imagem" no editor e foto automática nos posts da IA (a IA dá as palavras em inglês).
  **PT-PT obrigatório e sem falhas**: todos os textos da IA passam por um revisor de português europeu (2.ª chamada) e pela lista fixa `PostWriter::PT_BR_PT`.
  Email ao aprovador quando há post para aprovar: Microsoft 365 pela API Graph (`MSGRAPH_*` no `.env`), com botões "Aprovar" e
  "Pedir alteração" (`EmailDecisionController`): links assinados, válidos 7 dias e só para aquela versão do post, que abrem
  uma página de confirmação (o M365 abre os links para os verificar, por isso abrir o link nunca decide nada).
  Estas funcionalidades foram feitas diretamente no servidor a 28 out 2026 e depois trazidas para o Git: **alterações ao código devem passar sempre pelo repositório**.
- **Servidor (instalado a 27 out 2026):** http://192.168.1.69:8091 (só rede interna). Ubuntu 24.04, servidor partilhado com
  outras apps (nexus-ops, nexus-tempos, portal, advocacia): **não mexer nelas**.
  Pasta `/var/www/nexus-redes-sociais`, utilizador `app-social`, pool PHP-FPM próprio, PostgreSQL (base `nexus_social`),
  Apache em `sites-available/nexus-redes-sociais.conf` (porta 8091).
  Acesso SSH: `ssh -i ~/.ssh/nexus_painel dev@192.168.1.69` (chave criada para isto; a `nexus_prod` é de outra coisa).
  Atualizar: empacotar `painel-laravel` (sem vendor/.env/sqlite), copiar para `/tmp` e correr
  `sudo bash deploy/instalar-servidor.sh /tmp/painel.tgz` (mantém .env e dados).
  No servidor, os comandos do Claude correm com `sudo -u app-social php artisan ...` dentro da pasta.
  Permissões (padrão da advocacia): código `root:app-social` só de leitura para a app; a app só escreve em `storage/`, `bootstrap/cache/` e `public/imagens-posts/`.
  O browser das imagens corre como `app-render` (sem acesso ao `.env` nem ao código), via sudo restrito a esse binário
  (`/etc/sudoers.d/nexus-redes-sociais-render`), numa pasta partilhada `/var/lib/nexus-render/trabalho` (`RENDER_UTILIZADOR`, `RENDER_PASTA`).
  O Chrome atualiza-se sozinho à segunda-feira (`deploy/atualizar-chrome.sh`, cron como root).
  Nota: o `instalar-servidor.sh` que corre é o que já está no servidor; quando o próprio script muda, correr a instalação duas vezes.
  Para o tinker como app-social usar um HOME temporário: `H=$(sudo -u app-social mktemp -d); sudo -u app-social env HOME=$H php artisan tinker`.

## Próximos passos / em aberto
- Receber o logótipo em alta resolução e, se possível, fundos sem texto dos posts atuais.
- Decidir a IA de imagens (OpenAI ou Google) e obter a chave quando sair da fase de testes.
- Definir quem aprova e por onde (painel web, email, Teams ou WhatsApp).
- Definir quantos posts por semana (sugestão: 3 a 4) e criar modelos fixos por tipo (aviso, dica, produto, evento, Reel).
- Fase seguinte: app na Meta for Developers e API do Instagram/Facebook. Depois o LinkedIn (Community Management API).
- O Claude Motion (animações em MP4, planos Team/Enterprise) pode servir para Reels.
- Repositório Git: https://github.com/Nexus-Developer1/Nexus-Redes-Sociais (privado, ramo `main`; transferido da conta Rui-Moreira06). Nunca enviar `.env`, bases de dados nem palavras-passe.
