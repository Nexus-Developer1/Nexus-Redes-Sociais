# Converte posts\<nome>\post.html numa imagem PNG com o Microsoft Edge (sem janela).
# Exemplo: .\scripts\render.ps1 -Post posts\dica-backup -Largura 1080 -Altura 1350
param(
    [Parameter(Mandatory = $true)][string]$Post,
    [int]$Largura = 1080,
    [int]$Altura = 1080,
    [string]$Saida = "post.png"
)

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
if (-not (Test-Path $edge)) { throw "Microsoft Edge não encontrado em $edge" }

$pasta = (Resolve-Path $Post).Path
$html = Join-Path $pasta "post.html"
if (-not (Test-Path $html)) { throw "Não existe $html" }
$png = Join-Path $pasta $Saida

& $edge --headless=new --disable-gpu --hide-scrollbars `
    "--window-size=$Largura,$Altura" --force-device-scale-factor=1 `
    --virtual-time-budget=8000 --allow-file-access-from-files `
    "--screenshot=$png" ("file:///" + ($html -replace '\\', '/')) | Out-Null

Start-Sleep -Seconds 2
if (Test-Path $png) { Write-Host "Imagem criada: $png" } else { Write-Error "Falhou a criar $png" }
