#!/usr/bin/env bash
# deploy/deploy.sh — Деплой PreLend на VPS (Ubuntu 22/24 + Nginx + PHP-FPM)
#
# Уже настроенный сервер после git pull (сокет nginx ↔ php-fpm): см. deploy/UPGRADE_AFTER_GIT_PULL.md
#
# Использование:
#   sudo bash deploy/deploy.sh
# SOPS (секреты из secrets.enc.env + age.key), одна команда-обёртка:
#   sudo PRELEND_DOMAIN=... PRELEND_SOPS_ROOT=... SOPS_AGE_KEY_FILE=... bash deploy/vps_one_command.sh
#
# После обновления кода из git (без полного deploy):
#   sudo bash deploy/reload_services.sh
#
# Предварительно (без SOPS):
#   1. Скопируй .env в корень проекта на VPS
#   2. Убедись что домен указан в DNS на IP этого VPS
#   3. Запускай от root

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Глобальные параметры деплоя можно хранить здесь:
# /etc/default/prelend-deploy (опционально).
if [[ -f /etc/default/prelend-deploy ]]; then
    set -a
    # shellcheck disable=SC1091
    source /etc/default/prelend-deploy
    set +a
fi

# ── Конфиг (меняй под свой стенд) ────────────────────────────────────────────
PRELEND_USE_SOPS="${PRELEND_USE_SOPS:-0}"
PRELEND_SOPS_ROOT="${PRELEND_SOPS_ROOT:-}"
DOMAIN="${PRELEND_DOMAIN:-yourdomain.me}"
WEBROOT="/var/www/prelend"
NGINX_CONF="/etc/nginx/sites-available/prelend"
PHP_VER="8.3"
# Отдельный сокет пула [prelend], чтобы не конфликтовать со стандартным pool www (php8.3-fpm.sock).
PHP_FPM_LISTEN="/run/php/php${PHP_VER}-fpm-prelend.sock"
PYTHON="/usr/bin/python3"
REPO="https://github.com/NomelLime/PreLend.git"
GIT_TOKEN="${GIT_TOKEN:-}"    # если приватный репо
DEPLOY_USER="www-data"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
log()  { echo -e "${GREEN}[deploy]${NC} $*"; }
warn() { echo -e "${YELLOW}[warn]${NC}  $*"; }
die()  { echo -e "${RED}[error]${NC} $*"; exit 1; }

# ── 0. Preflight: env + backup ────────────────────────────────────────────────
log "Preflight: проверка окружения и бэкап критичных файлов..."
if [[ "${DOMAIN}" == "yourdomain.me" ]]; then
    warn "PRELEND_DOMAIN не задан (используется значение по умолчанию: ${DOMAIN})"
    warn "Рекомендуется запускать так: PRELEND_DOMAIN=example.com sudo bash deploy/deploy.sh"
fi
if [[ "${PRELEND_USE_SOPS}" == "1" ]]; then
    [[ -n "${PRELEND_SOPS_ROOT}" ]] || die "PRELEND_USE_SOPS=1 требует PRELEND_SOPS_ROOT"
    [[ -n "${SOPS_AGE_KEY_FILE:-}" ]] || die "PRELEND_USE_SOPS=1 требует SOPS_AGE_KEY_FILE"
fi

BACKUP_STAMP="$(date -u +%Y%m%d_%H%M%S)"
BACKUP_DIR="/var/backups/prelend-deploy/${BACKUP_STAMP}"
mkdir -p "${BACKUP_DIR}"
log "Backup dir: ${BACKUP_DIR}"

backup_if_exists() {
    local src="$1"
    local name="$2"
    if [[ -e "${src}" ]]; then
        cp -a "${src}" "${BACKUP_DIR}/${name}"
    fi
}

backup_if_exists "/etc/nginx/sites-available/prelend" "nginx_prelend.conf"
backup_if_exists "/etc/php/${PHP_VER}/fpm/pool.d/prelend.conf" "php_fpm_prelend.conf"
backup_if_exists "/var/www/prelend/data/clicks.db" "clicks.db"

# ── 1. Зависимости ────────────────────────────────────────────────────────────
log "Устанавливаем зависимости..."
apt-get update -qq
apt-get install -y -qq \
    nginx \
    php${PHP_VER}-fpm php${PHP_VER}-cli php${PHP_VER}-sqlite3 \
    python3 python3-pip python3-venv \
    sqlite3 git curl certbot python3-certbot-nginx unzip composer

# ── 2. Клонируем / обновляем репозиторий ──────────────────────────────────────
log "Деплоим код в ${WEBROOT}..."
if [[ -d "${WEBROOT}/.git" ]]; then
    git -C "${WEBROOT}" pull origin main
    log "Репозиторий обновлён (git pull)"
else
    REPO_URL="${REPO}"
    [[ -n "${GIT_TOKEN}" ]] && REPO_URL="https://${GIT_TOKEN}@github.com/NomelLime/PreLend.git"
    git clone "${REPO_URL}" "${WEBROOT}"
    log "Репозиторий склонирован"
fi

# ── 3. Создаём директории ──────────────────────────────────────────────────────
log "Создаём директории..."
mkdir -p "${WEBROOT}/data" "${WEBROOT}/logs"
chmod 775 "${WEBROOT}/data" "${WEBROOT}/logs"
chown -R "${DEPLOY_USER}:${DEPLOY_USER}" "${WEBROOT}/data" "${WEBROOT}/logs"

# ── 4. Инициализируем БД ──────────────────────────────────────────────────────
DB_PATH="${WEBROOT}/data/clicks.db"
if [[ ! -f "${DB_PATH}" ]]; then
    log "Создаём SQLite БД..."
    sqlite3 "${DB_PATH}" < "${WEBROOT}/data/init_db.sql"
    chown "${DEPLOY_USER}:${DEPLOY_USER}" "${DB_PATH}"
    log "БД создана: ${DB_PATH}"
else
    log "БД уже существует: ${DB_PATH}"
fi

# ── 5. Python зависимости ─────────────────────────────────────────────────────
log "Устанавливаем Python зависимости..."
pip3 install --break-system-packages -r "${WEBROOT}/requirements.txt" -q
log "Python зависимости установлены"

# ── 5a. PHP зависимости (Composer) ────────────────────────────────────────────
log "Устанавливаем PHP зависимости (MaxMind/IP2Location)..."
if [[ ! -f "${WEBROOT}/composer.json" ]]; then
    cat > "${WEBROOT}/composer.json" <<'JSON'
{
  "require": {
    "maxmind-db/reader": "^1.12",
    "ip2location/ip2location-php": "^8.4"
  }
}
JSON
fi
composer -d "${WEBROOT}" install --no-interaction --prefer-dist --no-progress
log "PHP зависимости установлены"

# Internal API (systemd ExecStart = venv/bin/uvicorn) — без venv будет status=203/EXEC
log "Venv для Internal API: ${WEBROOT}/venv ..."
if [[ ! -x "${WEBROOT}/venv/bin/python3" ]]; then
    python3 -m venv "${WEBROOT}/venv"
fi
"${WEBROOT}/venv/bin/pip" install -q --upgrade pip
"${WEBROOT}/venv/bin/pip" install -q -r "${WEBROOT}/internal_api/requirements.txt"
chown -R "${DEPLOY_USER}:${DEPLOY_USER}" "${WEBROOT}/venv"
log "venv готов (uvicorn: ${WEBROOT}/venv/bin/uvicorn)"

# ── 5b. SOPS → /run/prelend.env (PRELEND_USE_SOPS=1, см. deploy/vps_one_command.sh) ──
if [[ "${PRELEND_USE_SOPS}" == "1" ]]; then
    log "Режим SOPS: age, sops, systemd, расшифровка..."
    [[ -n "${PRELEND_SOPS_ROOT}" ]] || die "PRELEND_USE_SOPS=1 требует PRELEND_SOPS_ROOT"
    [[ -f "${PRELEND_SOPS_ROOT}/secrets.enc.env" ]] || die "Нет ${PRELEND_SOPS_ROOT}/secrets.enc.env"
    [[ -f "${PRELEND_SOPS_ROOT}/.sops.yaml" ]] || die "Нет ${PRELEND_SOPS_ROOT}/.sops.yaml"
    SOPS_KEY="${SOPS_AGE_KEY_FILE:-/etc/prelend/age.key}"
    [[ -f "${SOPS_KEY}" ]] || die "Нет ключа age: ${SOPS_KEY} (скопируй age.key на сервер, chmod 600)"

    apt-get install -y -qq age
    if ! command -v sops &>/dev/null; then
        _arch=$(uname -m)
        case "${_arch}" in
            x86_64) _sarch=amd64 ;;
            aarch64) _sarch=arm64 ;;
            *) die "Архитектура ${_arch}: установи sops в PATH вручную" ;;
        esac
        _sver=3.9.4
        curl -fsSL "https://github.com/getsops/sops/releases/download/v${_sver}/sops-v${_sver}.linux.${_sarch}" -o /usr/local/bin/sops
        chmod +x /usr/local/bin/sops
        log "Установлен sops ${_sver} (${_sarch}) → /usr/local/bin/sops"
    fi

    mkdir -p /etc/prelend
    chmod 700 /etc/prelend 2>/dev/null || true

    cat > /etc/default/prelend-secrets <<EOF
SOPS_AGE_KEY_FILE=${SOPS_KEY}
PRELEND_SOPS_ROOT=${PRELEND_SOPS_ROOT}
EOF
    chmod 600 /etc/default/prelend-secrets

    install -m 755 "${SCRIPT_DIR}/prelend-run-cron.sh" /usr/local/bin/prelend-run-cron.sh

    cat > /usr/local/bin/prelend-decrypt-env.sh <<DECRYPT
#!/usr/bin/bash
set -euo pipefail
if [[ -f /etc/default/prelend-secrets ]]; then
  set -a
  # shellcheck disable=SC1091
  source /etc/default/prelend-secrets
  set +a
fi
: "\${SOPS_AGE_KEY_FILE:?}"
: "\${PRELEND_SOPS_ROOT:?}"
export SOPS_AGE_KEY_FILE
cd "\${PRELEND_SOPS_ROOT}"
umask 077
_tmp=\$(mktemp)
sops -d --input-type dotenv --output-type dotenv secrets.enc.env > "\${_tmp}"
{
  echo ""
  echo "# PreLend paths (deploy.sh)"
  echo "PRELEND_DB=${WEBROOT}/data/clicks.db"
  echo "PRELEND_LOG=${WEBROOT}/logs/errors.log"
} >> "\${_tmp}"
mv "\${_tmp}" /run/prelend.env
chmod 600 /run/prelend.env
DECRYPT
    chmod 700 /usr/local/bin/prelend-decrypt-env.sh

    cat > /etc/systemd/system/prelend-decrypt-env.service <<UNIT
[Unit]
Description=Decrypt PreLend secrets (SOPS) to /run/prelend.env
DefaultDependencies=no
Before=php${PHP_VER}-fpm.service
After=local-fs.target

[Service]
Type=oneshot
RemainAfterExit=yes
EnvironmentFile=/etc/default/prelend-secrets
ExecStart=/usr/local/bin/prelend-decrypt-env.sh

[Install]
WantedBy=multi-user.target
UNIT

    mkdir -p "/etc/systemd/system/php${PHP_VER}-fpm.service.d"
    cat > "/etc/systemd/system/php${PHP_VER}-fpm.service.d/prelend-sops.conf" <<PHPDROP
[Unit]
After=prelend-decrypt-env.service
Requires=prelend-decrypt-env.service

[Service]
EnvironmentFile=/run/prelend.env
PHPDROP

    mkdir -p /etc/systemd/system/prelend-internal-api.service.d
    cat > /etc/systemd/system/prelend-internal-api.service.d/prelend-sops.conf <<APIDROP
[Unit]
After=prelend-decrypt-env.service
Requires=prelend-decrypt-env.service
APIDROP

fi

# ── 5c. Systemd unit Internal API (всегда, не только SOPS) ───────────────────
cat > /etc/systemd/system/prelend-internal-api.service <<APIUNIT
[Unit]
Description=PreLend Internal API
After=network.target

[Service]
User=${DEPLOY_USER}
Group=${DEPLOY_USER}
WorkingDirectory=${WEBROOT}
ExecStart=${WEBROOT}/venv/bin/uvicorn internal_api.main:app --host 127.0.0.1 --port 9090 --no-access-log
Restart=always
RestartSec=5
EnvironmentFile=-/run/prelend.env
NoNewPrivileges=yes
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
APIUNIT

# ── 6. .env файл ──────────────────────────────────────────────────────────────
if [[ "${PRELEND_USE_SOPS}" == "1" ]]; then
    log "SOPS: секреты в окружении php-fpm и Internal API (/run/prelend.env)"
    touch "${WEBROOT}/.env"
    chmod 600 "${WEBROOT}/.env"
    chown "${DEPLOY_USER}:${DEPLOY_USER}" "${WEBROOT}/.env"
elif [[ ! -f "${WEBROOT}/.env" ]]; then
    if [[ -f "${WEBROOT}/.env.example" ]]; then
        cp "${WEBROOT}/.env.example" "${WEBROOT}/.env"
        warn ".env создан из .env.example — ЗАПОЛНИ ЗНАЧЕНИЯ: ${WEBROOT}/.env"
    else
        die ".env отсутствует и .env.example не найден"
    fi
    chmod 600 "${WEBROOT}/.env"
    chown "${DEPLOY_USER}:${DEPLOY_USER}" "${WEBROOT}/.env"
else
    log ".env уже существует"
    chmod 600 "${WEBROOT}/.env"
    chown "${DEPLOY_USER}:${DEPLOY_USER}" "${WEBROOT}/.env"
fi

# ── 7. PHP-FPM (раньше nginx: сокет должен существовать до reload nginx) ───────
log "Настраиваем PHP-FPM..."
PHP_POOL="/etc/php/${PHP_VER}/fpm/pool.d/prelend.conf"
CLEAR_ENV_LINE=""
[[ "${PRELEND_USE_SOPS}" == "1" ]] && CLEAR_ENV_LINE=$'clear_env = no\n; переменные из systemd EnvironmentFile=/run/prelend.env (SOPS)'
cat > "${PHP_POOL}" << PHP_POOL_CONF
[prelend]
user  = ${DEPLOY_USER}
group = ${DEPLOY_USER}
listen = ${PHP_FPM_LISTEN}
listen.owner = ${DEPLOY_USER}
listen.group = www-data
listen.mode  = 0660

pm = dynamic
pm.max_children      = 10
pm.start_servers     = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4

php_admin_value[error_log]   = ${WEBROOT}/logs/php_errors.log
php_admin_flag[log_errors]   = on
php_admin_value[memory_limit] = 128M
php_admin_value[max_execution_time] = 60
${CLEAR_ENV_LINE}
; Без SOPS: postback — env[PL_POSTBACK_TOKEN] здесь (см. .env.example), затем reload php-fpm
PHP_POOL_CONF

systemctl restart php${PHP_VER}-fpm
log "PHP-FPM перезапущен"

# ── 8. Nginx конфиг ───────────────────────────────────────────────────────────
log "Настраиваем Nginx для ${DOMAIN}..."
cat > "${NGINX_CONF}" << NGINX
server {
    listen 80;
    server_name ${DOMAIN} www.${DOMAIN};

    root ${WEBROOT}/public;
    index index.php;

    # Логи
    access_log /var/log/nginx/prelend_access.log;
    error_log  /var/log/nginx/prelend_error.log;

    # Блокируем прямой доступ к служебным файлам (как в deploy/nginx.conf)
    location ~ ^/(config|data|src|monitor|agents|templates|deploy|logs|tests)/ {
        deny all;
        return 404;
    }

    # .env и .git точно закрыты
    location ~ /\.(env|git) {
        deny all;
        return 404;
    }

    # Internal API доступен только локально/через SSH tunnel
    location /internal_api {
        deny all;
        return 404;
    }

    # Заголовки безопасности
    add_header X-Frame-Options        "SAMEORIGIN"  always;
    add_header X-Content-Type-Options "nosniff"     always;
    add_header Referrer-Policy        "no-referrer" always;

    # Постбэк — отдельный location для ясности
    location = /postback.php {
        fastcgi_pass  unix:${PHP_FPM_LISTEN};
        fastcgi_index index.php;
        include       fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 90s;
        # Ограничение rate для постбэков (не более 30/сек)
        limit_req zone=postback burst=30 nodelay;
    }

    # PHP
    location ~ \.php\$ {
        fastcgi_pass  unix:${PHP_FPM_LISTEN};
        fastcgi_index index.php;
        include       fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 90s;
    }

    # Bio-ссылки с UTM (Сессия 12C)
    # /t/acc → TikTok bio, /i/acc → Instagram bio, /y/acc → YouTube About
    location ~ ^/t/(?<acc>[a-zA-Z0-9_-]+)\$ {
        rewrite ^ /index.php?utm_source=tiktok&utm_medium=bio&utm_campaign=\$acc&\$args last;
    }
    location ~ ^/i/(?<acc>[a-zA-Z0-9_-]+)\$ {
        rewrite ^ /index.php?utm_source=instagram&utm_medium=bio&utm_campaign=\$acc&\$args last;
    }
    location ~ ^/y/(?<acc>[a-zA-Z0-9_-]+)\$ {
        rewrite ^ /index.php?utm_source=youtube&utm_medium=bio&utm_campaign=\$acc&\$args last;
    }
    location ~ ^/go/(?<tag>[a-zA-Z0-9_-]+)\$ {
        rewrite ^ /index.php?utm_source=bio&utm_campaign=\$tag&\$args last;
    }

    # Всё остальное → index.php
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # Статика (если появится)
    location ~* \.(css|js|png|jpg|ico|woff2?)\$ {
        expires 30d;
        access_log off;
    }

    # Скрываем версию nginx
    server_tokens off;
}
NGINX

# Rate limit зона для постбэков
if ! grep -q "limit_req_zone.*postback" /etc/nginx/nginx.conf; then
    sed -i '/http {/a\    limit_req_zone $binary_remote_addr zone=postback:10m rate=30r/s;' /etc/nginx/nginx.conf
fi
if ! grep -q "real_ip_header CF-Connecting-IP;" /etc/nginx/nginx.conf; then
    sed -i '/http {/a\    real_ip_recursive on;\n    set_real_ip_from 131.0.72.0/22;\n    set_real_ip_from 172.64.0.0/13;\n    set_real_ip_from 104.24.0.0/14;\n    set_real_ip_from 104.16.0.0/13;\n    set_real_ip_from 162.158.0.0/15;\n    set_real_ip_from 198.41.128.0/17;\n    set_real_ip_from 197.234.240.0/22;\n    set_real_ip_from 188.114.96.0/20;\n    set_real_ip_from 190.93.240.0/20;\n    set_real_ip_from 108.162.192.0/18;\n    set_real_ip_from 141.101.64.0/18;\n    set_real_ip_from 103.31.4.0/22;\n    set_real_ip_from 103.22.200.0/22;\n    set_real_ip_from 103.21.244.0/22;\n    real_ip_header CF-Connecting-IP;' /etc/nginx/nginx.conf
fi

ln -sf "${NGINX_CONF}" /etc/nginx/sites-enabled/prelend 2>/dev/null || true
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
nginx -t && systemctl reload nginx
log "Nginx настроен"

# ── 9. Cron задачи ────────────────────────────────────────────────────────────
log "Устанавливаем cron задачи..."
CRON_FILE="/etc/cron.d/prelend"
CRON_WRAP=""
[[ "${PRELEND_USE_SOPS}" == "1" ]] && CRON_WRAP="/usr/local/bin/prelend-run-cron.sh "
cat > "${CRON_FILE}" << CRON_CONF
# PreLend cron задачи (сгенерировано deploy.sh)
PRELEND=${WEBROOT}
PYTHON=${PYTHON}
LOGDIR=${WEBROOT}/logs

# health_check — каждые 5 минут
*/5 * * * *  ${DEPLOY_USER}  ${CRON_WRAP}\$PYTHON \$PRELEND/monitor/health_check.py >> \$LOGDIR/health_check.log 2>&1

# daily_digest — каждый день в 09:00 UTC
0 9 * * *    ${DEPLOY_USER}  ${CRON_WRAP}\$PYTHON \$PRELEND/monitor/daily_digest.py >> \$LOGDIR/daily_digest.log 2>&1

# shave_detector + ANALYST — каждый день в 10:00 UTC
0 10 * * *   ${DEPLOY_USER}  ${CRON_WRAP}\$PYTHON \$PRELEND/monitor/shave_detector.py --analyst >> \$LOGDIR/shave_detector.log 2>&1

# test_conversions send — каждое воскресенье в 12:00 UTC
0 12 * * 0   ${DEPLOY_USER}  ${CRON_WRAP}\$PYTHON \$PRELEND/monitor/test_conversions.py send >> \$LOGDIR/test_conversions.log 2>&1

# test_conversions report — каждый понедельник в 09:30 UTC
30 9 * * 1   ${DEPLOY_USER}  ${CRON_WRAP}\$PYTHON \$PRELEND/monitor/test_conversions.py report >> \$LOGDIR/test_conversions.log 2>&1

# retry postbacks — каждые 10 минут
*/10 * * * * ${DEPLOY_USER}  ${CRON_WRAP}\$PYTHON \$PRELEND/cron/retry_postbacks.py >> \$LOGDIR/retry_postbacks.log 2>&1

# GEO базы (MaxMind/IP2Location) — каждый понедельник в 03:20 UTC
20 3 * * 1   ${DEPLOY_USER}  ${CRON_WRAP}/usr/bin/bash \$PRELEND/deploy/update_geo_bases.sh >> \$LOGDIR/geo_bases_update.log 2>&1
CRON_CONF
chmod 644 "${CRON_FILE}"
log "Cron задачи установлены: ${CRON_FILE}"

# ── 10. Права доступа ──────────────────────────────────────────────────────────
# Скрипт запускается от root: chown доступен. Важно: не делать «chown -R root:www-data
# на весь WEBROOT» — это затирает владельца на config/data/logs и ломает запись www-data.
log "Выставляем права доступа..."
chown root:"${DEPLOY_USER}" "${WEBROOT}"
shopt -s nullglob
for item in "${WEBROOT}"/*; do
    base=$(basename "${item}")
    case "${base}" in
        config|data|logs)
            # Запись от www-data: Internal API (config/), SQLite и логи (data/, logs/)
            ;;
        *)
            chown -R root:"${DEPLOY_USER}" "${item}"
            ;;
    esac
done
shopt -u nullglob
# Не chmod 640 на venv/bin/* — снимется +x с uvicorn/python → systemd 203/EXEC.
find "${WEBROOT}" -type f ! -path "${WEBROOT}/venv/*" -exec chmod 640 {} \;
find "${WEBROOT}" -type d -exec chmod 750 {} \;
if [[ -d "${WEBROOT}/venv" ]]; then
    find "${WEBROOT}/venv" -type f -exec chmod 644 {} \;
    find "${WEBROOT}/venv/bin" -type f -exec chmod 750 {} \;
fi
# public/ должен читаться nginx
chmod 755 "${WEBROOT}/public"
chmod 644 "${WEBROOT}/public"/*.php
# Скрипты мониторинга должны запускаться
chmod 750 "${WEBROOT}/monitor"/*.py "${WEBROOT}/agents"/*.py 2>/dev/null || true
chmod 750 "${WEBROOT}/deploy/update_geo_bases.sh" 2>/dev/null || true
# config/, data/, logs/ — владелец www-data (запись: Internal API, БД, cron)
chmod 775 "${WEBROOT}/data" "${WEBROOT}/logs" "${WEBROOT}/config"
chown -R "${DEPLOY_USER}:${DEPLOY_USER}" "${WEBROOT}/data" "${WEBROOT}/logs" "${WEBROOT}/config"
find "${WEBROOT}/config" -type f -exec chmod 664 {} \;

# ── 11. SSL (Certbot) ─────────────────────────────────────────────────────────
if [[ "${DOMAIN}" != "yourdomain.me" ]]; then
    log "Получаем SSL сертификат для ${DOMAIN}..."
    mkdir -p "${WEBROOT}/logs"
    certbot --nginx -d "${DOMAIN}" -d "www.${DOMAIN}" \
        --non-interactive --agree-tos --email "admin@${DOMAIN}" \
        --redirect 2>>"${WEBROOT}/logs/certbot_deploy.log" && log "SSL установлен" || warn "SSL не удался — см. ${WEBROOT}/logs/certbot_deploy.log"
else
    warn "DOMAIN не задан — SSL пропускаем. Укажи: PRELEND_DOMAIN=yourdomain.me bash deploy.sh"
fi

# ── 12. Перезагрузка Nginx, PHP-FPM, Internal API ──────────────────────────────
systemctl daemon-reload
if [[ "${PRELEND_USE_SOPS}" == "1" ]]; then
    systemctl enable prelend-decrypt-env.service
    systemctl start prelend-decrypt-env.service || die "SOPS: расшифровка не удалась (ключ, .sops.yaml, secrets.enc.env)"
    log "SOPS: записан /run/prelend.env"
fi
systemctl enable prelend-internal-api.service

if [[ -f "${WEBROOT}/deploy/reload_services.sh" ]]; then
    log "Перезапуск веб-стека и Internal API (reload_services.sh)..."
    bash "${WEBROOT}/deploy/reload_services.sh" || warn "reload_services.sh завершился с ошибкой"
else
    warn "Нет ${WEBROOT}/deploy/reload_services.sh — перезапусти сервисы вручную"
fi

# ── 13. Smoke test деплоя ──────────────────────────────────────────────────────
log "Проверяем деплой..."
sleep 2

if curl -sf "http://localhost/" -o /dev/null -w "%{http_code}" | grep -q "200\|301\|302"; then
    log "HTTP ответ получен ✅"
else
    warn "HTTP ответ не получен — проверь nginx и PHP-FPM"
fi

if [[ -f "${DB_PATH}" ]]; then
    tables=$(sqlite3 "${DB_PATH}" ".tables" 2>/dev/null | tr ' ' '\n' | sort | tr '\n' ' ')
    log "Таблицы в БД: ${tables}"
else
    warn "БД не найдена: ${DB_PATH}"
fi

# ── 14. Post-check: service status + logs ─────────────────────────────────────
log "Post-check: systemctl status (nginx/php-fpm/internal-api)..."
systemctl status nginx --no-pager -l || true
systemctl status "php${PHP_VER}-fpm" --no-pager -l || true
if systemctl cat prelend-internal-api.service &>/dev/null || [[ -f /etc/systemd/system/prelend-internal-api.service ]]; then
    systemctl status prelend-internal-api --no-pager -l || true
else
    warn "prelend-internal-api.service не найден"
fi

if [[ "${DOMAIN}" != "yourdomain.me" ]]; then
    log "Post-check: curl -I https://${DOMAIN}"
    curl -sI "https://${DOMAIN}" || true
else
    warn "Post-check HTTPS пропущен: PRELEND_DOMAIN не задан"
fi

log "Post-check logs: prelend_error.log, certbot_deploy.log, geo_bases_update.log"
[[ -f /var/log/nginx/prelend_error.log ]] && tail -n 30 /var/log/nginx/prelend_error.log || true
[[ -f "${WEBROOT}/logs/certbot_deploy.log" ]] && tail -n 30 "${WEBROOT}/logs/certbot_deploy.log" || true
[[ -f "${WEBROOT}/logs/geo_bases_update.log" ]] && tail -n 30 "${WEBROOT}/logs/geo_bases_update.log" || true

# ── Итог ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  PreLend задеплоен на ${DOMAIN}${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "  Следующие шаги:"
if [[ "${PRELEND_USE_SOPS}" == "1" ]]; then
    echo "  • Секреты: обнови ${PRELEND_SOPS_ROOT}/secrets.enc.env → systemctl start prelend-decrypt-env && systemctl reload php${PHP_VER}-fpm"
    echo "  • Опционально локально:    nano ${WEBROOT}/.env"
else
    echo "  • Заполни .env:            nano ${WEBROOT}/.env"
fi
echo "  • Конфиги:                 nano ${WEBROOT}/config/advertisers.json"
echo "  • telegram_router — на ПК разработчика"
echo "  • Постбэк:                 https://${DOMAIN}/postback.php?click_id=test"
echo "  • Логи:                    tail -f ${WEBROOT}/logs/*.log"
echo "  • Бэкап перед деплоем:     ${BACKUP_DIR}"
echo "  • После git pull:          sudo bash ${WEBROOT}/deploy/reload_services.sh"
echo ""
