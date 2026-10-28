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
- O HTML é convertido em PNG com o Microsoft Edge em headless (`scripts/render.ps1`).
- Texto, logótipos e fotos de produtos **nunca** são gerados por IA: o texto é posto em código; logótipos e fotos de produtos vêm de ficheiros oficiais.
- Para testes usámos o Pollinations (gratuito, sem chave): https://image.pollinations.ai/prompt/<texto>?width=1080&height=1350&nologo=true
  Limitações: 1 imagem a cada 45 s, baixa resolução (~686x858), marca de água no canto inferior direito, ignora detalhes do pedido.
  Para produção: API paga (OpenAI ou Google), poucos cêntimos por imagem.

## Ficheiros
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
  Ainda não publica na Meta (fica para a fase da API).
- **Servidor (instalado a 27 out 2026):** http://192.168.1.69:8091 (só rede interna). Ubuntu 24.04, servidor partilhado com
  outras apps (nexus-ops, nexus-tempos, portal, advocacia): **não mexer nelas**.
  Pasta `/var/www/nexus-redes-sociais`, utilizador `app-social`, pool PHP-FPM próprio, PostgreSQL (base `nexus_social`),
  Apache em `sites-available/nexus-redes-sociais.conf` (porta 8091).
  Acesso SSH: `ssh -i ~/.ssh/nexus_painel dev@192.168.1.69` (chave criada para isto; a `nexus_prod` é de outra coisa).
  Atualizar: empacotar `painel-laravel` (sem vendor/.env/sqlite), copiar para `/tmp` e correr
  `sudo bash deploy/instalar-servidor.sh /tmp/painel.tgz` (mantém .env e dados).
  No servidor, os comandos do Claude correm com `sudo -u app-social php artisan ...` dentro da pasta.

## Próximos passos / em aberto
- Receber o logótipo em alta resolução e, se possível, fundos sem texto dos posts atuais.
- Decidir a IA de imagens (OpenAI ou Google) e obter a chave quando sair da fase de testes.
- Definir quem aprova e por onde (painel web, email, Teams ou WhatsApp).
- Definir quantos posts por semana (sugestão: 3 a 4) e criar modelos fixos por tipo (aviso, dica, produto, evento, Reel).
- Fase seguinte: app na Meta for Developers e API do Instagram/Facebook. Depois o LinkedIn (Community Management API).
- O Claude Motion (animações em MP4, planos Team/Enterprise) pode servir para Reels.
- Repositório Git: https://github.com/Nexus-Developer1/Nexus-Redes-Sociais (privado, ramo `main`; transferido da conta Rui-Moreira06). Nunca enviar `.env`, bases de dados nem palavras-passe.
