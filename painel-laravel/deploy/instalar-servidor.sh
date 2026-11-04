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

echo "== permissões durante a instalação (o composer e o artisan, como $USR, precisam de escrever)"
chown -R "$USR":www-data "$DIR"
find "$DIR" -type d -exec chmod 750 {} +
find "$DIR" -type f -exec chmod 640 {} +
chmod 600 "$DIR/.env"
chmod 750 "$DIR/artisan"

echo "== browser com utilizador próprio (sem acesso ao .env nem ao código)"
# O Chrome abre imagens que vêm de fora (uploads, Pixabay): se um dia houver uma falha nele, quem a explorar
# fica com um utilizador que não lê o .env (chaves) nem consegue mudar o código ou as imagens publicadas.
RUSR=app-render
RDIR=/var/lib/nexus-render
id "$RUSR" >/dev/null 2>&1 || useradd --system --home-dir "$RDIR" --shell /usr/sbin/nologin "$RUSR"
mkdir -p "$RDIR/trabalho"
chown "$RUSR":"$RUSR" "$RDIR"
chmod 755 "$RDIR"
# pasta de trabalho partilhada: a app escreve as páginas, o browser grava as imagens (grupo do browser, setgid)
chown "$USR":"$RUSR" "$RDIR/trabalho"
chmod 2770 "$RDIR/trabalho"
cat > /etc/sudoers.d/$APP-render <<EOF
# NEXUS Redes Sociais: a app ($USR) só pode correr o browser como $RUSR (gerado por instalar-servidor.sh)
$USR ALL=($RUSR) NOPASSWD: /opt/chrome-headless-shell/chrome-headless-shell
EOF
chmod 440 /etc/sudoers.d/$APP-render
visudo -cf /etc/sudoers.d/$APP-render >/dev/null
set_env RENDER_UTILIZADOR "$RUSR"
set_env RENDER_PASTA "$RDIR/trabalho"

echo "== dependências"
cd "$DIR"
as_app composer install --no-dev --optimize-autoloader --no-interaction --no-progress -q
grep -q '^APP_KEY=base64' .env || as_app php artisan key:generate --force -n

echo "== base de dados"
as_app php artisan migrate --force -n
# Só cria o aprovador e os exemplos se ainda não existirem.
as_app env APROVADOR_PASSWORD="${APROVADOR_PASSWORD:-}" php artisan db:seed --force -n

as_app php artisan optimize -n

echo "== permissões finais: o código fica só de leitura para a app (mesmo padrão da advocacia)"
# Dono root, grupo $USR: o PHP-FPM e o cron (que correm como $USR) leem o código e o .env,
# mas não os conseguem alterar. Se um dia houver uma falha que deixe escrever ficheiros
# através do site, não dá para mexer no código do painel.
chown -R root:"$USR" "$DIR"
find "$DIR" -type d -exec chmod 750 {} +
find "$DIR" -type f -exec chmod 640 {} +
chmod 751 "$DIR"            # o Apache só atravessa a pasta (não lista nem lê o código)
chmod 640 "$DIR/.env"
# public/: servida pelo Apache (www-data); tem só o que o site já mostra (CSS, JS, modelos, imagens).
find "$DIR/public" -type d -exec chmod 755 {} +
find "$DIR/public" -type f -exec chmod 644 {} +
# Onde a app escreve: logs e cache (storage, bootstrap/cache) e imagens dos posts e da biblioteca.
for w in storage bootstrap/cache public/imagens-posts; do
    mkdir -p "$DIR/$w"
    chown -R "$USR":"$USR" "$DIR/$w"
done
find "$DIR/storage" "$DIR/bootstrap/cache" -type d -exec chmod 750 {} +
find "$DIR/storage" "$DIR/bootstrap/cache" -type f -exec chmod 640 {} +

echo "== browser para gerar as imagens dos posts (chrome-headless-shell em /opt, só para esta app)"
bash "$DIR/deploy/atualizar-chrome.sh"

echo "== agendador (publica os posts aprovados à hora marcada; sem chave da Meta não faz nada)"
cat > /etc/cron.d/$APP <<EOF
# NEXUS Redes Sociais: tarefas agendadas do Laravel (post:publicar a cada 5 min).
* * * * * $USR cd $DIR && php artisan schedule:run >> /dev/null 2>&1
# Chrome sempre atualizado (correções de segurança): segunda-feira às 04:30.
30 4 * * 1 root bash $DIR/deploy/atualizar-chrome.sh >> /var/log/$APP-chrome.log 2>&1
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
    # Na pasta onde a app escreve (imagens dos posts e da biblioteca) nunca se executa código.
    <Directory "$DIR/public/imagens-posts">
        <FilesMatch "\\.(php|phtml|phar|pht|php[0-9])$">
            Require all denied
        </FilesMatch>
        RemoveHandler .php .phtml .phar
        Options -ExecCGI
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
