#!/usr/bin/env bash
# ============================================================
# Atualiza o chrome-headless-shell (o browser que gera as imagens dos posts) para a versão estável
# mais recente. Corre na instalação e, sozinho, todas as semanas (cron em /etc/cron.d/nexus-redes-sociais):
# o Chrome recebe correções de segurança com frequência e abre imagens que vêm de fora.
#
#   sudo bash atualizar-chrome.sh
# ============================================================
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Correr com sudo."; exit 1; }

CHS=/opt/chrome-headless-shell
VER=$(curl -fsS https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions.json \
    | grep -o '"Stable":{[^}]*}' | grep -o '"version":"[^"]*"' | cut -d'"' -f4 || true)
ATUAL=$("$CHS/chrome-headless-shell" --version 2>/dev/null | grep -o '[0-9][0-9.]*' | head -1 || true)

if [ -x "$CHS/chrome-headless-shell" ] && { [ -z "$VER" ] || [ "$VER" = "$ATUAL" ]; }; then
    echo "$(date '+%F %T') chrome-headless-shell ${ATUAL} já é a versão mais recente"
    exit 0
fi
[ -n "$VER" ] || { echo "Não consegui saber a versão estável do Chrome (sem internet?)"; exit 1; }

apt-get install -y -qq unzip libnss3 libnspr4 libatk1.0-0t64 libatk-bridge2.0-0t64 libcups2t64 libdrm2 \
    libxkbcommon0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1 libpango-1.0-0 libcairo2 \
    libasound2t64 libdbus-1-3 libexpat1 >/dev/null
TMPZ=$(mktemp -d)
trap 'rm -rf "$TMPZ"' EXIT
curl -fsSL -o "$TMPZ/chs.zip" "https://storage.googleapis.com/chrome-for-testing-public/$VER/linux64/chrome-headless-shell-linux64.zip"
unzip -q "$TMPZ/chs.zip" -d "$TMPZ"
# troca de uma vez: um render a meio usa a versão antiga até acabar
rm -rf "$CHS.novo"
mv "$TMPZ/chrome-headless-shell-linux64" "$CHS.novo"
chmod -R a+rX "$CHS.novo"
rm -rf "$CHS.antigo"
[ -d "$CHS" ] && mv "$CHS" "$CHS.antigo"
mv "$CHS.novo" "$CHS"
rm -rf "$CHS.antigo"
echo "$(date '+%F %T') instalado chrome-headless-shell $VER (antes: ${ATUAL:-nenhum})"
# Bibliotecas em falta aparecem aqui (a lista deve ficar vazia).
ldd "$CHS/chrome-headless-shell" | grep "not found" || true
