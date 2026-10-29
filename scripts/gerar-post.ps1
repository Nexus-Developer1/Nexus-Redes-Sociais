# Gera um post a partir dos modelos (pasta modelos\) e de um dados.json.
#   .\scripts\gerar-post.ps1 -Pasta posts\2026-11-workstations
# Cria na pasta: post.html (a partir do modelo) e post.png (imagem final).
# Avisa se o texto não couber, se faltar uma imagem ou se o modelo der erro.
param(
    [Parameter(Mandatory = $true)][string]$Pasta,
    [string]$Saida = "post.png"
)
$ErrorActionPreference = "Stop"

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
if (-not (Test-Path $edge)) { throw "Microsoft Edge não encontrado em $edge" }

$pasta = (Resolve-Path $Pasta).Path.TrimEnd('\')
$ficheiroDados = Join-Path $pasta "dados.json"
if (-not (Test-Path $ficheiroDados)) { throw "Não existe $ficheiroDados" }

$utf8 = New-Object System.Text.UTF8Encoding($false)
$json = [IO.File]::ReadAllText($ficheiroDados, [Text.Encoding]::UTF8)
try { $dados = $json | ConvertFrom-Json } catch { throw "dados.json inválido: $($_.Exception.Message)" }

$formatos = @{ "4:5" = @(1080, 1350); "1:1" = @(1080, 1080); "9:16" = @(1080, 1920) }
$formato = if ($dados.formato) { [string]$dados.formato } else { "4:5" }
if (-not $formatos.ContainsKey($formato)) { throw "Formato inválido: $formato (use 4:5, 1:1 ou 9:16)" }
$largura, $altura = $formatos[$formato]

# Caminho relativo da pasta do post até modelos\ (para o post.html funcionar em qualquer pasta).
$modelos = Join-Path (Split-Path $PSScriptRoot -Parent) "painel-laravel\public\modelos"
$rel = ([Uri]($pasta + "\")).MakeRelativeUri([Uri]($modelos + "\")).ToString()

# Os dados entram como JSON dentro de <script>; "</" é escapado para não fechar a tag.
$jsonSeguro = $json.Trim() -replace '</', '<\/'
$html = @"
<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8">
<!-- Gerado por scripts/gerar-post.ps1 a partir de dados.json. Alterar o dados.json, não este ficheiro. -->
<link href="${rel}modelos.css" rel="stylesheet">
</head>
<body>
<script>window.POST = $jsonSeguro;</script>
<script src="${rel}modelos.js"></script>
</body>
</html>
"@
$ficheiroHtml = Join-Path $pasta "post.html"
[IO.File]::WriteAllText($ficheiroHtml, $html, $utf8)
$url = "file:///" + ($ficheiroHtml -replace '\\', '/')
$png = Join-Path $pasta $Saida
if (Test-Path $png) { Remove-Item $png }

$opcoes = @("--headless=new", "--disable-gpu", "--hide-scrollbars", "--force-device-scale-factor=1",
          "--virtual-time-budget=10000", "--allow-file-access-from-files", "--window-size=$largura,$altura",
          "--enable-logging=stderr", "--v=0", "--screenshot=$png", $url,
          # perfil à parte, para não interferir com o Edge que estiver aberto
          "--user-data-dir=$env:TEMP\nexus-gerar-post-edge")

# O modelos.js escreve "NEXUS-ESTADO" e "NEXUS-AVISO" na consola; o Edge passa-os para o stderr.
# (No PowerShell 5.1, o stderr de um programa não pode parar o script.)
$ErrorActionPreference = "Continue"
$registo = (& $edge @opcoes 2>&1 | ForEach-Object { "$_" }) -join " "
$ErrorActionPreference = "Stop"

if (-not (Test-Path $png)) { throw "Falhou a criar $png" }
Write-Host "Imagem criada: $png ($largura x $altura)"

if ($registo -notmatch 'NEXUS-ESTADO:pronto') {
    Write-Warning "O modelo não terminou (erro no modelos.js ou fontes sem internet?). Abra o post.html no Edge para ver."
    exit 2
}
$avisos = [regex]::Matches($registo, '"NEXUS-AVISO:(.*?)", source') | ForEach-Object { $_.Groups[1].Value }
if ($avisos) {
    foreach ($a in $avisos) { Write-Warning $a }
    exit 2
}
Write-Host "Sem avisos: o texto cabe e as imagens foram encontradas."
