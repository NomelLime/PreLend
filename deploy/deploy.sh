#!/usr/bin/env bash
# deploy/deploy.sh — Деплой PreLend на VPS (Ubuntu 22/24 + Nginx + PHP-FPM)
#
# Уже настроенный сервер после git pull (сокет nginx ↔ php-fpm): см. deploy/UPGRADE_AFTER_GIT_PULL.md
#
# Использование:
#   bash deploy/deploy.sh
# После обновления кода из git (без полного deploy):
#   sudo bash deploy/reload_services.sh
#
# Предварительно:
#   1. Скопируй .env в корень проекта на VPS
#   2. Убедись что домен указан в DNS на IP этого VPS
#   3. Запускай от root или sudo-пользователя

set -euo pipefail

# ── Конфиг (меняй под свой стенд) ────────────────────────────────────────────
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

# ── 1. Зависимости ────────────────────────────────────────────────────────────
log "Устанавливаем зависимости..."
apt-get update -qq
apt-get install -y -qq \
    nginx \
    php${PHP_VER}-fpm php${PHP_VER}-cli php${PHP_VER}-sqlite3 \
    python3 python3-pip python3-venv \
    sqlite3 git curl certbot python3-certbot-nginx

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

# ── 6. .env файл ──────────────────────────────────────────────────────────────
if [[ ! -f "${WEBROOT}/.env" ]]; then
    if [[ -f "${WEBROOT}/.env.example" ]]; then
        cp "${WEBROOT}/.env.example" "${WEBROOT}/.env"
        warn ".env создан из .env.example — ЗАПОЛНИ ЗНАЧЕНИЯ: ${WEBROOT}/.env"
    else
        die ".env отсутствует и .env.example не найден"
    fi
else
    log ".env уже существует"
fi
chmod 600 "${WEBROOT}/.env"
chown "${DEPLOY_USER}:${DEPLOY_USER}" "${WEBROOT}/.env"

# ── 7. PHP-FPM (раньше nginx: сокет должен существовать до reload nginx) ───────
log "Настраиваем PHP-FPM..."
PHP_POOL="/etc/php/${PHP_VER}/fpm/pool.d/prelend.conf"
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
; Postback: задайте env[PL_POSTBACK_TOKEN] здесь (см. .env.example), затем reload php-fpm
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

    # Скрываем версию nginx
    server_tokens off;
}
NGINX

# Rate limit зона для постбэков
if ! grep -q "limit_req_zone.*postback" /etc/nginx/nginx.conf; then
    sed -i '/http {/a\    limit_req_zone $binary_remote_addr zone=postback:10m rate=30r/s;' /etc/nginx/nginx.conf
fi

ln -sf "${NGINX_CONF}" /etc/nginx/sites-enabled/prelend 2>/dev/null || true
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
nginx -t && systemctl reload nginx
log "Nginx настроен"

# ── 9. Cron задачи ────────────────────────────────────────────────────────────
log "Устанавливаем cron задачи..."
CRON_FILE="/etc/cron.d/prelend"
cat > "${CRON_FILE}" << CRON_CONF
# PreLend cron задачи (сгенерировано deploy.sh)
PRELEND=${WEBROOT}
PYTHON=${PYTHON}
LOGDIR=${WEBROOT}/logs

# health_check — каждые 5 минут
*/5 * * * *  ${DEPLOY_USER}  \$PYTHON \$PRELEND/monitor/health_check.py >> \$LOGDIR/health_check.log 2>&1

# daily_digest — каждый день в 09:00 UTC
0 9 * * *    ${DEPLOY_USER}  \$PYTHON \$PRELEND/monitor/daily_digest.py >> \$LOGDIR/daily_digest.log 2>&1

# shave_detector + ANALYST — каждый день в 10:00 UTC
0 10 * * *   ${DEPLOY_USER}  \$PYTHON \$PRELEND/monitor/shave_detector.py --analyst >> \$LOGDIR/shave_detector.log 2>&1

# test_conversions send — каждое воскресенье в 12:00 UTC
0 12 * * 0   ${DEPLOY_USER}  \$PYTHON \$PRELEND/monitor/test_conversions.py send >> \$LOGDIR/test_conversions.log 2>&1

# test_conversions report — каждый понедельник в 09:30 UTC
30 9 * * 1   ${DEPLOY_USER}  \$PYTHON \$PRELEND/monitor/test_conversions.py report >> \$LOGDIR/test_conversions.log 2>&1
CRON_CONF
chmod 644 "${CRON_FILE}"
log "Cron задачи установлены: ${CRON_FILE}"

# ── 10. Права доступа ──────────────────────────────────────────────────────────
log "Выставляем права доступа..."
chown -R root:"${DEPLOY_USER}" "${WEBROOT}"
find "${WEBROOT}" -type f -exec chmod 640 {} \;
find "${WEBROOT}" -type d -exec chmod 750 {} \;
# public/ должен читаться nginx
chmod 755 "${WEBROOT}/public"
chmod 644 "${WEBROOT}/public"/*.php
# Скрипты мониторинга должны запускаться
chmod 750 "${WEBROOT}/monitor"/*.py "${WEBROOT}/agents"/*.py 2>/dev/null || true
# Данные и логи — доступ для www-data
chmod 775 "${WEBROOT}/data" "${WEBROOT}/logs"
chown -R "${DEPLOY_USER}:${DEPLOY_USER}" "${WEBROOT}/data" "${WEBROOT}/logs"

# ── 11. SSL (Certbot) ─────────────────────────────────────────────────────────
if [[ "${DOMAIN}" != "yourdomain.me" ]]; then
    log "Получаем SSL сертификат для ${DOMAIN}..."
    certbot --nginx -d "${DOMAIN}" -d "www.${DOMAIN}" \
        --non-interactive --agree-tos --email "admin@${DOMAIN}" \
        --redirect 2>/dev/null && log "SSL установлен" || warn "SSL не удался — продолжаем без HTTPS"
else
    warn "DOMAIN не задан — SSL пропускаем. Укажи: PRELEND_DOMAIN=yourdomain.me bash deploy.sh"
fi

# ── 12. Smoke test деплоя ──────────────────────────────────────────────────────
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

# ── 13. Перезагрузка Nginx, PHP-FPM, Internal API ──────────────────────────────
if [[ -f "${WEBROOT}/deploy/reload_services.sh" ]]; then
    log "Перезапуск веб-стека и Internal API (reload_services.sh)..."
    bash "${WEBROOT}/deploy/reload_services.sh" || warn "reload_services.sh завершился с ошибкой"
else
    warn "Нет ${WEBROOT}/deploy/reload_services.sh — перезапусти сервисы вручную"
fi

# ── Итог ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  PreLend задеплоен на ${DOMAIN}${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "  Следующие шаги:"
echo "  1. Заполни .env:           nano ${WEBROOT}/.env"
echo "  2. Заполни конфиги:        nano ${WEBROOT}/config/advertisers.json"
echo "  3. Запусти telegram_router на ПК разработчика"
echo "  4. Проверь постбэк-URL:    https://${DOMAIN}/postback.php?click_id=test"
echo "  5. Проверь логи:           tail -f ${WEBROOT}/logs/*.log"
echo "  6. После git pull:         sudo bash ${WEBROOT}/deploy/reload_services.sh"
echo ""
