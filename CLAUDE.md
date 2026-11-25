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
  **Padrão do feed (24 nov):** os posts continuam o que já está no Instagram/Facebook da NEXUS — logótipo branco ao centro em
  cima, foto a ocupar o post (pouco escurecida), título em caixas #004D31 em baixo à esquerda, texto de apoio em maiúsculas,
  sem etiqueta nem barra de rodapé; produto e montra com o cabeçalho verde "We make IT for you" (`modelos/email/cabecalho.png`);
  carrossel com "›››". O estilo antigo (fundo escuro, barras verdes, rodapé com o site) já não se usa.
  Os posts da IA são sempre 4:5 (o prompt já não oferece 1:1; `PostWriter::normalizar` força-o). A pré-visualização do Instagram
  mostra os 1:1 antigos inteiros (`.ig-galeria--quadrado`). Datas: a IA recebe um calendário dos próximos 45 dias com os feriados
  (`AvisoDatas::calendarioParaIa`) e, depois do revisor, `AvisoDatas::acertarDiasDaSemana` corrige o dia da semana de cada
  "<dia>, D de <mês>" (e o "no próximo"/"na próxima"): a IA escrevia "Sábado, 30 de novembro" num ano em que é segunda.
  Cada post novo = pasta com `dados.json` → `.\scripts\gerar-post.ps1 -Pasta <pasta>` cria `post.html` + `post.png`
  e avisa (código 2) se o texto não couber ou faltar uma imagem. Usar sempre os modelos para posts novos.
  **Editor no painel** (para a equipa): "+ Criar post" e "Editar" → `PostEditorController`; a imagem é gerada no servidor pelo
  `App\Services\PostRenderer` (chrome-headless-shell em `/opt/chrome-headless-shell`; no Windows usa o Edge). Dados do modelo em `posts.design`,
  imagens em `public/imagens-posts/design/<id>/`.
- `posts/aviso-fecho/`: aviso "Amanhã estamos fechados" (23 out 2026), 100% em código.
- `posts/dica-backup/`: dica da regra 3-2-1 de backup, com fundo do Pollinations.
- `posts/nvidia-elite-partner/`: post NVIDIA Elite Partner feito à mão (exemplo).
- O protótipo inicial do painel (pasta `painel/`) foi apagado a 24 nov; continua no histórico do git e em
  https://claude.ai/artifact/V5St7mNn398ZmY99MaAYgc
- `painel-laravel/`: **painel de aprovação a sério**, em Laravel 13 + SQLite (PHP 8.3 e Composer em `C:\Users\dev01\tools\php83`).
  Sem Vite/npm. **Aspeto = ADN Nexus** (desde 23 nov 2026): `public/css/suite.css` é o sistema de desenho partilhado com o portal,
  a Nexus Infra e a Knowledgebase (verde #16A34A, Poppins, barra lateral escura `painel/lateral.blade.php`). **Não se edita aqui**:
  tem de ficar igual nas apps (copiar de `Desktop/portal/public/css/suite.css`). O que é só deste painel fica em `public/css/painel.css`.
  O `suite.css` usa `.caixa`, `.legenda`, `.filtros`, `.procura`, `.lateral`, `.principal` e `.topo` com outro sentido: no painel usar
  `.bloco`, `.legenda-post`, `.filtros-bib`, `.procura-img`. Páginas sem barra lateral: `@section('sem_lateral', '1')`.
  Organização (23 nov): cada página = `<div class="pagina">` + `<x-cabecalho titulo= sub=>botões</x-cabecalho>` (componente em
  `resources/views/components/cabecalho.blade.php`). Na fila não há coluna à direita: "Pedir um post à IA" é uma janela (`#dlg-pedir`).
  Cartão do post: só imagem, título, data · redes, legenda e botões; o secundário (Instagram, Stories, imagem grande, outra foto,
  origem, histórico) está no menu "⋯" (`details.mais-opcoes`). Clicar na imagem abre a pré-visualização do Instagram
  (`painel/instagram.blade.php`). O estado só aparece quando diz algo (Atrasado, Em criação, Alteração pedida).
  Editor: uma coluna de campos por passos (1 Texto da imagem · 2 Fundo · 3 Legenda · 4 Publicação) dentro de `#design`
  (o editor.js só lê `[data-campo]` dentro de `#design`); ícone, botão e tema (`rodape`, para a capa do email) em "Mais opções da imagem"; o campo "etiqueta" saiu a 25 nov (já não aparecia na imagem); na legenda,
  "Escrever com IA" à vista e o resto em "Melhorar com IA". Aviso: sai na véspera, por isso a "Data" da imagem = dia seguinte
  à publicação e o texto "Voltamos na …" = próximo dia útil (`App\Support\AvisoDatas`; o editor.js repete o cálculo quando se
  muda o dia e só troca campos vazios, o exemplo ou o último valor automático). "Eliminar post" (`posts.destroy`, `painel/eliminar.blade.php`) está
  no "⋯" do editor e do cartão: só para idea/creating/pending/revising/rejected (`Post::ELIMINAVEIS`), nunca se já saiu numa rede
  nem com a IA a trabalhar nele (`motivoParaNaoEliminar`); apaga as imagens e o histórico e fica uma linha no laravel.log. Login obrigatório; cada decisão fica no histórico (`post_reviews`) com quem e quando.
  Arrancar: `cd painel-laravel; php artisan serve` → http://localhost:8000. Testes: `php artisan test`.
  Estados do post: idea, creating, pending, revising, approved, rejected, published.
  Comandos (correm sozinhos no servidor; os antigos post:pendentes, post:submeter e post:ideia, do tempo em que o Claude fazia
  os posts à mão, foram apagados a 24 nov — hoje tudo entra pelo painel e pela IA automática):
  - `php artisan post:publicar [--simular]`: publica na Meta os posts aprovados cuja hora chegou (cron a cada 5 min no servidor). Sem `META_PAGE_TOKEN` no `.env` não publica nada.
    Um só processo de cada vez (cache lock). Nada sai mais de 6 h depois da hora marcada (`PublicarPosts::MAX_ATRASO_HORAS`): fica uma nota para mudar a data.
    Depois de 3 falhas, o cartão tem "Tentar publicar de novo".
  - `php artisan post:lembrar`: de hora a hora (8h–20h) envia um lembrete por email dos posts ainda por aprovar a menos de 48 h da data (um por versão, `posts.lembrete_em`).
  - `php artisan ia:noticias [--pedido]` — **página "Notícias de IA"** (desde 24 nov): o agente lê os feeds RSS/Atom de
    `config/ia.php` `fontes_noticias` (9 fontes: OpenAI, Google AI, NVIDIA, Hugging Face, Microsoft, AWS ML, TechCrunch, The Verge,
    MIT Tech Review; só texto, últimos 10 dias, 15 por fonte, guarda em `noticias_ia` sem repetir o link) e a IA faz o resumo
    (`PostWriter::resumirNoticias`, JSON: título, introdução, 4–7 destaques com os ids das notícias, "para a NEXUS", ideias de posts;
    guardado em `resumos_ia`). Segunda e quinta às 08:00; "Recolher agora" põe um pedido em cache que o `--pedido` (a cada minuto)
    atende. O texto dos feeds vai à IA como DADOS (nunca instruções) e só se mostra escapado; a IA só pode citar ids da lista.
    Em cada destaque e ideia há "Pedir um post sobre isto" (um pedido à IA, como o "Pedir um post à IA").
    **Duas vertentes** (24 nov, página "Notícias" no menu): "Inteligência Artificial" (Resumo, Notícias, Resumos anteriores) e
    "Parceiros tecnológicos" (`?ver=parceiros`), escolhidas em dois cartões no topo (`.vertentes`).
    **Parceiros tecnológicos**: as notícias das 13 marcas de `config/ia.php` `fontes_parceiros`
    (AMD, Intel, NVIDIA, Supermicro, Micron, Microsoft, VMware, DataCore, Open-E, Altaro, Riello UPS, Netgear, Ubiquiti), filtro por
    marca e "Post" em cada notícia. Ficam na mesma
    tabela com `noticias_ia.parceiro` = true (`NoticiaIa::deParceiros()` / `deIa()`), até 30 dias e 6 por marca, e **não entram no
    resumo da IA** (a Groq gratuita não aguenta). Intel, Netgear, Ubiquiti e Micron não têm RSS que funcione: vão pelo Google
    Notícias (títulos sem o " - site", páginas genéricas com menos de 4 palavras ficam de fora). Logótipos oficiais a cores em
    `public/img/marcas/<marca>.svg|png` (Wikimedia Commons e sites das marcas; Riello UPS e Open-E do site oficial; Altaro, DataCore
    e Ubiquiti só o símbolo, por isso a etiqueta mostra também o nome). Os SVG foram verificados: sem scripts.
  - **Menu (24 nov):** a página "Pedidos de posts" juntou-se a "Ideias e em criação": no topo dessa secção, os pedidos à IA ainda
    sem post (a fazer agora, com rodinha, ou que falharam, com o erro e "Tentar outra vez"; `painel/pedidos.blade.php`). Os pedidos
    de uma ideia já aparecem no cartão da ideia. `?ver=pedidos` abre "Ideias e em criação". O número do menu conta também esses pedidos. **"Publicados" e "Rejeitados" são agora uma só entrada, "Histórico"** (`?ver=historico&estado=publicados|rejeitados`,
    `PainelController::HISTORICO`), com os separadores "Publicados | Rejeitados" e os números; vai por páginas e não tem número no menu.
    `?ver=publicados` e `?ver=rejeitados` continuam a funcionar.
  - **Interruptor dos emails:** `EMAILS_LIGADOS=false` no .env do servidor = não sai nenhum email (aprovação, lembretes, parceiros,
    testes); os posts continuam a ser feitos e o painel mostra "Emails desligados por agora". Nada fica marcado como enviado.
    Desligado a 24 nov a pedido (recomeço do zero); para ligar: EMAILS_LIGADOS=true e `php artisan config:cache`.
  - `php artisan post:enviar-parceiros [--simular]` (cron a cada 5 min): **posts também por email aos parceiros** (desde 24 nov).
    Lista na página "Parceiros" (`parceiros`: adicionar, colar do Excel/CSV, "Parar de enviar" = `ativo` false; importar nunca reativa
    quem saiu). **Regra (24 nov): cada post tem SEMPRE as duas vertentes** — redes sociais (Instagram, Facebook e LinkedIn sempre,
    `Post::REDES_SEMPRE`; a IA não tira nenhuma) e email aos parceiros (`posts.enviar_parceiros`, ligado por defeito). Só como exceção
    se desliga ("Não enviar este aos parceiros" no "⋯" ou no passo 4 do editor), e só antes de aprovar. Quem aprova vê as duas: o email
    de aprovação leva também a capa do email aos parceiros, e no painel "✉ Ver o email" (`posts.partnersPreview`) abre o email tal
    como sai. Os posts aprovados antes desta regra não mudaram (foram aprovados sem o email). Sai à hora marcada do post, só aprovado/publicado,
    até 6 h depois (como `post:publicar`, independente da Meta). Um email por parceiro (`App\Mail\PostParaParceiros`), registado em
    `parceiro_envios` (único por post+parceiro: nunca repete; 3 tentativas; até 120 por volta, 2,1 s entre emails = limite do M365).
    As imagens vão DENTRO do email (JPEG 720 px, `Miniatura::caminho`): os parceiros não chegam ao servidor interno. Legenda sem as
    linhas de hashtags; sair = link mailto para o remetente. Fim do envio: `posts.parceiros_enviado_em` + histórico; depois disso o post
    não se elimina. "Enviar-me um teste" manda o mesmo email só para quem carrega.
    Aspeto = modelo da newsletter da NEXUS no E-goi (versão web de "Esta sexta-feira estaremos encerrados"): cabeçalho verde com
    a diagonal, imagem do post, título #00BA4C, texto #4B4B4B, "WE MAKE IT FOR YOU", produtos (→ configurador), "Torne-se um parceiro"
    (→ /programa-parceiros-nexus/), marcas, contactos, redes e rodapé #C1D59F. Peças em `resources/email-parceiros/` (tiradas da
    versão web; o cabeçalho é o topo de OUT-OF-OFFICE.png recortado). Remetente "NEXUS IT Solutions" (`PARCEIROS_REMETENTE`).
    Capa do email = `PostRenderer::capaEmail()` (1200 de largura; a altura segue a foto, que NUNCA é cortada: só o canto da
    diagonal tapa uma ponta; fotos muito altas/largas aparam só as pontas até ficarem no máximo quadradas, conforme o
    enquadramento — nada de faixas desfocadas, ficavam feias; JPEG `email-<hash>.jpg` na pasta do post, refeita só se o post mudar):
    cabeçalho com a zona abaixo da diagonal transparente (`public/modelos/email/cabecalho.png`), a foto de fundo do post a começar
    na diagonal (y=282; nunca por trás do verde, senão esconde metade da foto; sem foto, verde da marca) e o tema (rodapé do post) em caixas #004D31 como "OUT / OF OFFICE". Se falhar, o email leva o
    cabeçalho e a imagem do post. Nos testes está desligada (`EMAIL_CAPA=false`).
    **Avisos** (`Post::isAviso()`): a capa é sempre a original da newsletter, escritório da NEXUS + "OUT OF OFFICE"
    (`PostRenderer::CAPA_EMAIL_AVISO` = `public/modelos/email/out-of-office.jpg`, 1200×800), a pedido a 24 nov.
    O **post do aviso nas redes** usa a mesma imagem (`modelos.js`, classe `.ooo`): em 4:5 o recorte do logótipo e das caixas
    "OUT / OF OFFICE" a fundir-se no verde, com a data e o "Voltamos na…" por baixo; em 1:1 e Stories a imagem inteira e a data
    por baixo. O título e a foto de fundo do aviso já não aparecem na imagem (o título fica para o painel e o texto alternativo).
  **Regras de segurança (23 nov 2026):** as decisões são atómicas (`Post::decide(..., $de)` só grava se o post ainda estiver nesses estados);
  a IA ao refazer só grava se o post ainda estiver à espera DESSA alteração (fica "Em criação" enquanto gera a imagem) e só muda a data/redes se o pedido falar delas;
  aprovar exige imagem e data; "Desfazer" é recusado se o post já saiu numa rede; pedidos "a fazer" há mais de 20 min passam a falhados.
  Miniaturas dos cartões e da biblioteca: `App\Support\Miniatura` (JPEG feito na 1.ª vez em `public/imagens-posts/mini/`).
  - `php artisan post:tratar-pedidos` (cron a cada minuto): a **IA automática** cria os posts de "Pedir um post" e refaz os que têm
    "Pedir alteração" (só posts com modelo; o pedido pode trazer a data de publicação, que a IA não muda), gera a imagem com os modelos e põe-nos "À espera de aprovação". Nunca aprova nem publica.
    Fornecedor com API formato OpenAI em `config/ia.php` (`IA_ENDERECO`, `IA_CHAVE`, `IA_MODELO`=qwen/qwen3.8-27b, o melhor em PT-PT); para testes: **Groq** (grátis),
    conta própria da NEXUS (não usar a chave da conta partilhada da advocacia). Sem `IA_CHAVE` não faz nada. Produção: talvez API do Claude (~1 €/mês).
  - `php artisan post:calendario-ia` (cron a cada minuto): **calendário automático**. A IA troca o tema das ideias com "Trocar tema",
    faz cada ideia sozinha `IA_IDEIAS_DIAS_ANTES` (7) dias antes da data e, de segunda a sexta, propõe uma vez por semana as ideias da semana
    seguinte até `IA_IDEIAS_POR_SEMANA` (3 por defeito; **1 no servidor desde 23 nov 2026**), a pensar nos feriados e dias especiais (`App\Services\DatasEspeciais`).
    Os **avisos não contam** (`Post::isAviso()`: modelo "aviso", "Tipo: aviso" ou título começado por "Aviso"); num feriado em dia útil sem aviso,
    a IA pode propor o aviso além do post da semana. Botão "✨ Propor ideias" no calendário
    (ou `--propor`). 0 em qualquer das duas desliga essa parte.
    **Avisos repetidos (25 nov):** cada aviso fala do primeiro feriado nos 10 dias a seguir à publicação (`Post::feriadoDoAviso`);
    um feriado que já tem aviso (mesmo a sair na semana anterior, como numa ponte) não pede outro (`Post::avisosDoFeriado`), e o
    cartão de um aviso com outro para o mesmo fecho mostra "Pode ser repetido" (`Post::avisosRepetidos`; sem feriado = mesmo dia). Ideias cujo pedido falhou não se repetem sozinhas (fica o botão).
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
  Ficheiros apagados no git saem também do servidor (desde 24 nov): o script guarda a lista do que cada deploy instala
  em `/var/lib/nexus-redes-sociais-deploy/ficheiros` (só do root) e apaga o que vinha no deploy anterior e já não vem.
  Só apaga ficheiros dessa lista: `.env`, uploads (`public/imagens-posts`), `storage` e `vendor` nunca são tocados.
  Para o tinker como app-social usar um HOME temporário: `H=$(sudo -u app-social mktemp -d); sudo -u app-social env HOME=$H php artisan tinker`.

## Próximos passos / em aberto
- Receber o logótipo em alta resolução e, se possível, fundos sem texto dos posts atuais.
- Decidir a IA de imagens (OpenAI ou Google) e obter a chave quando sair da fase de testes.
- Definir quem aprova e por onde (painel web, email, Teams ou WhatsApp).
- Decidido: **1 post por semana** (os avisos de fecho e feriados são extra). Falta um modelo para Reels.
- Fase seguinte: app na Meta for Developers e API do Instagram/Facebook. Depois o LinkedIn (Community Management API).
- O Claude Motion (animações em MP4, planos Team/Enterprise) pode servir para Reels.
- Repositório Git: https://github.com/Nexus-Developer1/Nexus-Redes-Sociais (privado, ramo `main`; transferido da conta Rui-Moreira06). Nunca enviar `.env`, bases de dados nem palavras-passe.
