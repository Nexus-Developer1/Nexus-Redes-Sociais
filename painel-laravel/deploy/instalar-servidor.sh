#!/usr/bin/env bash
# ============================================================
# Painel de aprovação NEXUS Redes Sociais — instalação/atualização no servidor.
#
#   sudo APROVADOR_PASSWORD='...' bash instalar-servidor.sh /tmp/painel.tgz
#
# Segue o padrão das outras aplicações do servidor:
#   - utilizador próprio (app-social), pool PHP-FPM próprio, base PostgreSQL própria
#   - servido só por IP, na porta 8091 (fora do infra.nexus-solutions.pt;
#     o default-deny das portas 80/443 não é tocado)
# Pode correr-se de novo para atualizar: mantém o .env, a base de dados e os uploads.
# ============================================================
set -euo pipefail

TGZ="${1:?indique o ficheiro .tgz do projeto}"
APP=nexus-redes-sociais
USR=app-social
DIR=/var/www/$APP
PORT=8091
SOCK=/run/php/$APP.sock
DB=nexus_social
DBUSER=app_social
IP=$(hostname -I | awk '{print $1}')

[ "$(id -u)" -eq 0 ] || { echo "Correr com sudo."; exit 1; }

echo "== utilizador"
id "$USR" >/dev/null 2>&1 || useradd --system --home-dir "$DIR" --shell /usr/sbin/nologin "$USR"

echo "== código"
mkdir -p "$DIR"
tar -xzf "$TGZ" -C "$DIR"
mkdir -p "$DIR"/storage/{logs,framework/{sessions,views,cache/data}} "$DIR"/bootstrap/cache

as_app() { sudo -u "$USR" env HOME=/tmp COMPOSER_HOME=/tmp/composer-$USR "$@"; }
set_env() { # set_env CHAVE valor  (substitui a linha, mesmo comentada, ou acrescenta)
    if grep -qE "^#? ?$1=" "$DIR/.env"; then
        sed -i "s|^#\? \?$1=.*|$1=$2|" "$DIR/.env"
    else
        echo "$1=$2" >> "$DIR/.env"
    fi
}

if [ ! -f "$DIR/.env" ]; then
    echo "== primeira instalação: base de dados e .env"
    DBPASS=$(openssl rand -hex 20)
    sudo -u postgres psql -v ON_ERROR_STOP=1 -qc "CREATE ROLE $DBUSER LOGIN PASSWORD '$DBPASS'"
    sudo -u postgres createdb -O "$DBUSER" "$DB"
    cp "$DIR/.env.example" "$DIR/.env"
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env APP_URL "http://$IP:$PORT"
    set_env LOG_LEVEL warning
    set_env DB_CONNECTION pgsql
    set_env DB_HOST 127.0.0.1
    set_env DB_PORT 5432
    set_env DB_DATABASE "$DB"
    set_env DB_USERNAME "$DBUSER"
    set_env DB_PASSWORD "$DBPASS"
    set_env APROVADOR_PASSWORD ""
fi

echo "== permissões"
chown -R "$USR":www-data "$DIR"
find "$DIR" -type d -exec chmod 750 {} +
find "$DIR" -type f -exec chmod 640 {} +
chmod 600 "$DIR/.env"
chmod 750 "$DIR/artisan"

echo "== dependências"
cd "$DIR"
as_app composer install --no-dev --optimize-autoloader --no-interaction --no-progress -q
grep -q '^APP_KEY=base64' .env || as_app php artisan key:generate --force -n

echo "== base de dados"
as_app php artisan migrate --force -n
# Só cria o aprovador e os exemplos se ainda não existirem.
as_app env APROVADOR_PASSWORD="${APROVADOR_PASSWORD:-}" php artisan db:seed --force -n

as_app php artisan optimize -n

echo "== browser para gerar as imagens dos posts (chrome-headless-shell em /opt, só para esta app)"
CHS=/opt/chrome-headless-shell
if [ ! -x "$CHS/chrome-headless-shell" ]; then
    apt-get install -y -qq unzip libnss3 libnspr4 libatk1.0-0t64 libatk-bridge2.0-0t64 libcups2t64 libdrm2 \
        libxkbcommon0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1 libpango-1.0-0 libcairo2 \
        libasound2t64 libdbus-1-3 libexpat1 >/dev/null
    VER=$(curl -fsS https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions.json \
        | grep -o '"Stable":{[^}]*}' | grep -o '"version":"[^"]*"' | cut -d'"' -f4)
    TMPZ=$(mktemp -d)
    curl -fsSL -o "$TMPZ/chs.zip" "https://storage.googleapis.com/chrome-for-testing-public/$VER/linux64/chrome-headless-shell-linux64.zip"
    unzip -q "$TMPZ/chs.zip" -d "$TMPZ"
    rm -rf "$CHS"
    mv "$TMPZ/chrome-headless-shell-linux64" "$CHS"
    rm -rf "$TMPZ"
    chmod -R a+rX "$CHS"
    echo "   instalado chrome-headless-shell $VER"
fi
# Bibliotecas em falta aparecem aqui (a lista deve ficar vazia).
ldd "$CHS/chrome-headless-shell" | grep "not found" || true

echo "== agendador (publica os posts aprovados à hora marcada; sem chave da Meta não faz nada)"
cat > /etc/cron.d/$APP <<EOF
# NEXUS Redes Sociais: tarefas agendadas do Laravel (post:publicar a cada 5 min).
* * * * * $USR cd $DIR && php artisan schedule:run >> /dev/null 2>&1
EOF
chmod 644 /etc/cron.d/$APP

echo "== PHP-FPM"
cat > /etc/php/8.3/fpm/pool.d/$APP.conf <<EOF
[$APP]
user = $USR
group = $USR
listen = $SOCK
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 4
pm.start_servers = 1
pm.min_spare_servers = 1
pm.max_spare_servers = 2
pm.max_requests = 500
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 20M
php_admin_value[error_log] = /var/log/php8.3-fpm-$APP.log
php_admin_flag[log_errors] = on
php_admin_flag[display_errors] = off
EOF
# o grupo do utilizador precisa de ler os ficheiros (dono app-social, grupo www-data)
php-fpm8.3 -t
systemctl reload php8.3-fpm

echo "== Apache"
cat > /etc/apache2/sites-available/$APP.conf <<EOF
# ============================================================
# NEXUS Redes Sociais (painel de aprovação) — só pelo IP do servidor, porta $PORT.
# Fora do infra.nexus-solutions.pt de propósito; o default-deny das portas 80/443
# (000-bloqueado.conf) não é tocado.
# Gerado por instalar-servidor.sh: alterações à mão perdem-se na próxima instalação.
# ============================================================
Listen $PORT

<VirtualHost *:$PORT>
    DocumentRoot $DIR/public

    <Directory "$DIR/public">
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
        RewriteEngine On
        RewriteCond %{HTTP:Authorization} .
        RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteRule ^ index.php [L]
        <FilesMatch "\.php$">
            SetHandler "proxy:unix:$SOCK|fcgi://localhost"
        </FilesMatch>
    </Directory>
    # o resto do projeto (.env, código) nunca é servido; só a pasta pública
    <Directory "$DIR">
        Require all denied
    </Directory>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "same-origin"

    ErrorLog \${APACHE_LOG_DIR}/$APP-error.log
    CustomLog \${APACHE_LOG_DIR}/$APP-access.log combined
</VirtualHost>
EOF
a2ensite -q $APP
apache2ctl configtest
systemctl reload apache2

echo "== pronto: http://$IP:$PORT"
