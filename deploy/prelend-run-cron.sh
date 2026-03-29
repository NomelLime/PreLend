#!/usr/bin/env bash
# Подхватывает /run/prelend.env (после SOPS) и запускает команду от имени cron.
set -euo pipefail
if [[ -f /run/prelend.env ]]; then
  set -a
  # shellcheck disable=SC1091
  source /run/prelend.env
  set +a
fi
exec "$@"
