<?php
/*
 * Editor de posts NEXUS: servidor local (só neste computador, 127.0.0.1).
 * Arranca com abrir-editor.bat na raiz do projeto:
 *   php -S 127.0.0.1:8020 -t . modelos/editor/servidor.php
 *
 * Serve só as pastas modelos/, marca/ e posts/ e tem 4 pedidos:
 *   GET  /api/posts   posts que já têm dados.json
 *   POST /api/novo    cria posts/<nome>/ a partir de um exemplo
 *   POST /api/imagem  guarda uma imagem (fundo, produto, parceiro) na pasta do post
 *   POST /api/gerar   grava o dados.json e corre scripts/gerar-post.ps1
 */
declare(strict_types=1);

$RAIZ = realpath(__DIR__ . '/../..');
$POSTS = $RAIZ . DIRECTORY_SEPARATOR . 'posts';
$MODELOS = ['dica', 'produto', 'aviso', 'evento'];
$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function responde(array $dados, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function erro(string $mensagem, int $codigo = 400): never
{
    responde(['erro' => $mensagem], $codigo);
}
function corpo(): array
{
    $d = json_decode((string) file_get_contents('php://input'), true);
    return is_array($d) ? $d : erro('Pedido inválido.');
}
function pasta_do_post(string $nome, bool $temDeExistir = true): string
{
    global $POSTS;
    if (! preg_match('/^[a-z0-9][a-z0-9-]{1,60}$/', $nome)) {
        erro('Nome inválido: use só letras minúsculas, números e hífens (ex.: 2026-11-servidores).');
    }
    $dir = $POSTS . DIRECTORY_SEPARATOR . $nome;
    if ($temDeExistir && ! is_file($dir . DIRECTORY_SEPARATOR . 'dados.json')) {
        erro('Esse post não existe.', 404);
    }
    return $dir;
}
function so_mesma_origem(): void
{
    // Impede que outra página aberta no browser use este servidor.
    $origem = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origem !== '' && ! preg_match('#^http://(127\.0\.0\.1|localhost):\d+$#', $origem)) {
        erro('Origem não permitida.', 403);
    }
}

// ---------- API ----------
if ($uri === '/') {
    header('Location: /modelos/editor/');
    exit;
}

if ($uri === '/api/posts' && $metodo === 'GET') {
    $lista = [];
    foreach (glob($POSTS . '/*/dados.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true) ?: [];
        $lista[] = [
            'pasta' => basename(dirname($f)),
            'modelo' => $d['modelo'] ?? '?',
            'titulo' => str_replace(["\n", '*'], [' ', ''], (string) ($d['titulo'] ?? '')),
            'alterado' => filemtime($f),
        ];
    }
    usort($lista, fn ($a, $b) => $b['alterado'] <=> $a['alterado']);
    responde(['posts' => $lista]);
}

if ($uri === '/api/novo' && $metodo === 'POST') {
    so_mesma_origem();
    $p = corpo();
    $modelo = (string) ($p['modelo'] ?? '');
    if (! in_array($modelo, $MODELOS, true)) {
        erro('Modelo inválido.');
    }
    $dir = pasta_do_post((string) ($p['pasta'] ?? ''), false);
    if (file_exists($dir)) {
        erro('Já existe um post com esse nome. Escolha outro ou abra o existente.');
    }
    // Copia o exemplo do modelo (dados.json + imagens), menos os ficheiros gerados.
    $exemplo = $RAIZ . "/modelos/exemplos/$modelo";
    mkdir($dir, 0775, true);
    foreach (scandir($exemplo) as $f) {
        if (is_file("$exemplo/$f") && ! in_array($f, ['post.html', 'post.png'], true)) {
            copy("$exemplo/$f", "$dir/$f");
        }
    }
    responde(['pasta' => basename($dir)]);
}

if ($uri === '/api/imagem' && $metodo === 'POST') {
    so_mesma_origem();
    $dir = pasta_do_post((string) ($_POST['pasta'] ?? ''));
    $f = $_FILES['ficheiro'] ?? null;
    if (! $f || $f['error'] !== UPLOAD_ERR_OK) {
        erro('A imagem não chegou (máximo 20 MB).');
    }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
        erro('Use PNG, JPG, WEBP ou SVG.');
    }
    $tipo = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (! preg_match('#^image/#', (string) $tipo)) {
        erro('O ficheiro não é uma imagem.');
    }
    $base = strtolower(pathinfo((string) $f['name'], PATHINFO_FILENAME));
    $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $base) ?: $base), '-') ?: 'imagem';
    $nome = substr($base, 0, 50) . '.' . $ext;
    if (in_array($nome, ['post.png', 'dados.json'], true)) {
        $nome = 'img-' . $nome;
    }
    move_uploaded_file($f['tmp_name'], "$dir/$nome") ?: erro('Não consegui guardar a imagem.', 500);
    responde(['nome' => $nome]);
}

if ($uri === '/api/gerar' && $metodo === 'POST') {
    so_mesma_origem();
    $p = corpo();
    $dir = pasta_do_post((string) ($p['pasta'] ?? ''));
    $dados = $p['dados'] ?? null;
    if (! is_array($dados) || ! in_array($dados['modelo'] ?? '', $MODELOS, true)) {
        erro('Dados do post inválidos.');
    }
    file_put_contents("$dir/dados.json", json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    // Corre o mesmo script que se usa no terminal. -EncodedCommand evita problemas com aspas.
    $script = str_replace("'", "''", $RAIZ . '\scripts\gerar-post.ps1');
    $pasta = str_replace("'", "''", $dir);
    $ps = "[Console]::OutputEncoding = [Text.Encoding]::UTF8; "
        . "& '$script' -Pasta '$pasta' 3>&1 2>&1 | ForEach-Object { "
        . "if (\$_ -is [Management.Automation.WarningRecord]) { 'AVISO: ' + \$_.Message } else { \"\$_\" } }; "
        . "exit \$LASTEXITCODE";
    $cmd = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand '
        . base64_encode(mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'));
    exec($cmd, $saida, $codigo);

    $avisos = [];
    foreach ($saida as $linha) {
        if (str_starts_with($linha, 'AVISO: ')) {
            $avisos[] = substr($linha, 7);
        }
    }
    $png = $dir . DIRECTORY_SEPARATOR . 'post.png';
    if (! is_file($png)) {
        erro('Não foi possível gerar a imagem: ' . implode(' ', $saida), 500);
    }
    responde([
        'ok' => $codigo === 0,
        'avisos' => $avisos,
        'png' => '/posts/' . basename($dir) . '/post.png?v=' . filemtime($png),
        'caminho' => $png,
    ]);
}

if (str_starts_with($uri, '/api/')) {
    erro('Pedido desconhecido.', 404);
}

// ---------- ficheiros: só modelos/, marca/ e posts/, nunca .php ----------
$alvo = realpath($RAIZ . str_replace('/', DIRECTORY_SEPARATOR, $uri));
$permitido = false;
foreach (['modelos', 'marca', 'posts'] as $p) {
    $raizPermitida = $RAIZ . DIRECTORY_SEPARATOR . $p;
    if ($alvo !== false && ($alvo === $raizPermitida || str_starts_with($alvo, $raizPermitida . DIRECTORY_SEPARATOR))) {
        $permitido = true;
    }
}
if (! $permitido || str_ends_with(strtolower($alvo), '.php')) {
    http_response_code(404);
    echo 'Não encontrado';
    exit;
}
header('Cache-Control: no-store');
return false; // o servidor do PHP entrega o ficheiro
