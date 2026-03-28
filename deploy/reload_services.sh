#!/usr/bin/env bash
# deploy/reload_services.sh — перезагрузка Nginx, PHP-FPM и Internal API после деплоя/обновления кода.
#
# Запуск на VPS (обычно от root):
#   sudo bash deploy/reload_services.sh
#
# Переменные окружения:
#   PHP_VER=8.3                    — версия PHP-FPM
#   PRELEND_INTERNAL_API_UNIT      — имя systemd-юнита (по умолчанию prelend-internal-api)
#   PRELEND_SKIP_INTERNAL_API=1    — не трогать Internal API (если юнит ещё не установлен)
#
# Сменили listen= в pool.d — лучше вручную: systemctl restart php*-fpm (см. deploy/UPGRADE_AFTER_GIT_PULL.md)

set -euo pipefail

PHP_VER="${PHP_VER:-8.3}"
UNIT_API="${PRELEND_INTERNAL_API_UNIT:-prelend-internal-api}"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
log()  { echo -e "${GREEN}[reload]${NC} $*"; }
warn() { echo -e "${YELLOW}[warn]${NC}  $*"; }
die()  { echo -e "${RED}[error]${NC} $*"; exit 1; }

if [[ "${EUID:-0}" -ne 0 ]]; then
  die "Запусти с sudo: sudo bash deploy/reload_services.sh"
fi

log "nginx reload..."
nginx -t && systemctl reload nginx

log "php${PHP_VER}-fpm reload..."
systemctl reload "php${PHP_VER}-fpm"

if [[ "${PRELEND_SKIP_INTERNAL_API:-0}" == "1" ]]; then
  warn "PRELEND_SKIP_INTERNAL_API=1 — пропуск Internal API"
  log "Готово."
  exit 0
fi

if systemctl cat "${UNIT_API}.service" &>/dev/null || [[ -f "/etc/systemd/system/${UNIT_API}.service" ]]; then
  if systemctl is-active --quiet "${UNIT_API}" 2>/dev/null; then
    log "${UNIT_API} restart..."
    systemctl restart "${UNIT_API}"
  else
    warn "${UNIT_API} не active — пробуем start"
    systemctl start "${UNIT_API}" || warn "start не удался"
  fi
else
  warn "Юнит ${UNIT_API}.service не найден — скопируй deploy/prelend-internal-api.service в /etc/systemd/system/ и systemctl daemon-reload"
fi

_have_unit=0
if systemctl cat "${UNIT_API}.service" &>/dev/null || [[ -f "/etc/systemd/system/${UNIT_API}.service" ]]; then
  _have_unit=1
fi

# После restart uvicorn поднимается не мгновенно — несколько попыток (иначе ложный warn «/health недоступен»).
_health_ok=0
if [[ "${PRELEND_SKIP_INTERNAL_API:-0}" != "1" ]] && [[ "${_have_unit}" -eq 1 ]]; then
  for _attempt in 1 2 3 4 5 6 7 8 9 10; do
    if curl -sf --connect-timeout 2 "http://127.0.0.1:9090/health" -o /dev/null; then
      _health_ok=1
      break
    fi
    sleep 0.5
  done
fi

log "Готово."
if [[ "${PRELEND_SKIP_INTERNAL_API:-0}" != "1" ]] && [[ "${_have_unit}" -eq 1 ]]; then
  if [[ "${_health_ok}" -eq 1 ]]; then
    log "Internal API /health OK"
  else
    warn "/health не ответил за ~5 с после restart (см. systemctl status ${UNIT_API} и journalctl -u ${UNIT_API} -n 30)"
  fi
fi
