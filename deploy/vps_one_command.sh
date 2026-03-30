#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════════
# PreLend на VPS «одной командой» с SOPS (расшифровка → /run/prelend.env).
#
# Подготовка на VPS (до запуска):
#   1) Каталог с файлами монорепы: secrets.enc.env и .sops.yaml (как в корне GitHub/).
#   2) Приватный ключ: скопируй age.key в безопасное место, например:
#        sudo mkdir -p /etc/prelend && sudo chmod 700 /etc/prelend
#        sudo install -m 600 -o root -g root ~/age.key /etc/prelend/age.key
#
# Запуск (из корня PreLend после git clone):
#   sudo PRELEND_DOMAIN=yourdomain.me \
#        PRELEND_SOPS_ROOT=/root/sops-secrets \
#        SOPS_AGE_KEY_FILE=/etc/prelend/age.key \
#        bash deploy/vps_one_command.sh
#
# Либо (один раз) создай /etc/default/prelend-deploy и запускай без переменных:
#   sudo install -m 600 -o root -g root /dev/stdin /etc/default/prelend-deploy <<'EOF'
# PRELEND_DOMAIN=yourdomain.me
# PRELEND_SOPS_ROOT=/root/sops-secrets
# SOPS_AGE_KEY_FILE=/etc/prelend/age.key
# PRELEND_ENABLE_COMPOSER=1   # опционально
# EOF
#   sudo bash deploy/vps_one_command.sh
#
# Одной строкой с клонированием (публичный репо):
#   sudo bash -c 'git clone https://github.com/NomelLime/PreLend.git /tmp/pl && \
#     PRELEND_DOMAIN=yourdomain.me PRELEND_SOPS_ROOT=/root/sops-secrets \
#     SOPS_AGE_KEY_FILE=/etc/prelend/age.key bash /tmp/pl/deploy/vps_one_command.sh'
#
# Требуется: root, Ubuntu 22.04/24.04, DNS домена на IP VPS (для certbot).
# ═══════════════════════════════════════════════════════════════════════════════

set -euo pipefail

[[ "${EUID:-0}" -ne 0 ]] && {
  echo "Запусти от root: sudo bash deploy/vps_one_command.sh"
  exit 1
}

# Подхватываем постоянные настройки деплоя (если есть).
if [[ -f /etc/default/prelend-deploy ]]; then
  set -a
  # shellcheck disable=SC1091
  source /etc/default/prelend-deploy
  set +a
fi

export PRELEND_USE_SOPS=1
export PRELEND_SOPS_ROOT="${PRELEND_SOPS_ROOT:?Задай PRELEND_SOPS_ROOT — каталог с secrets.enc.env и .sops.yaml}"
export SOPS_AGE_KEY_FILE="${SOPS_AGE_KEY_FILE:-/etc/prelend/age.key}"

[[ -f "${SOPS_AGE_KEY_FILE}" ]] || {
  echo "Нет файла ключа: ${SOPS_AGE_KEY_FILE}"
  exit 1
}
[[ -f "${PRELEND_SOPS_ROOT}/secrets.enc.env" ]] || {
  echo "Нет ${PRELEND_SOPS_ROOT}/secrets.enc.env"
  exit 1
}
[[ -f "${PRELEND_SOPS_ROOT}/.sops.yaml" ]] || {
  echo "Нет ${PRELEND_SOPS_ROOT}/.sops.yaml"
  exit 1
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec bash "${SCRIPT_DIR}/deploy.sh"
