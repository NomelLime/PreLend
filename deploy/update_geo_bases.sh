#!/usr/bin/env bash
# Обновление GEO-баз без внешних API в рантайме:
#   - MaxMind GeoLite2-Country.mmdb
#   - IP2Location LITE DB1 BIN
#
# Нужные переменные окружения:
#   MAXMIND_LICENSE_KEY   (обязательно для MaxMind)
# Опционально:
#   MAXMIND_EDITION_ID    (по умолчанию GeoLite2-Country)
#   IP2LOCATION_DB1_URL   (по умолчанию официальный LITE DB1 zip)
#   PRELEND_WEBROOT       (по умолчанию /var/www/prelend)

set -euo pipefail

PRELEND_WEBROOT="${PRELEND_WEBROOT:-/var/www/prelend}"
DATA_DIR="${PRELEND_WEBROOT}/data"
META_FILE="${DATA_DIR}/geo_bases_meta.json"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

mkdir -p "${DATA_DIR}"

MAXMIND_LICENSE_KEY="${MAXMIND_LICENSE_KEY:-}"
MAXMIND_EDITION_ID="${MAXMIND_EDITION_ID:-GeoLite2-Country}"
IP2LOCATION_DB1_URL="${IP2LOCATION_DB1_URL:-https://download.ip2location.com/lite/IP2LOCATION-LITE-DB1.BIN.ZIP}"

log() { echo "[geo-update] $*"; }
warn() { echo "[geo-update][warn] $*" >&2; }

sha256_file() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print $1}'
  else
    shasum -a 256 "$1" | awk '{print $1}'
  fi
}

download_maxmind() {
  if [[ -z "${MAXMIND_LICENSE_KEY}" ]]; then
    warn "MAXMIND_LICENSE_KEY не задан, пропускаю MaxMind"
    return 0
  fi

  local mm_url
  mm_url="https://download.maxmind.com/app/geoip_download?edition_id=${MAXMIND_EDITION_ID}&license_key=${MAXMIND_LICENSE_KEY}&suffix=tar.gz"
  local tarball="${TMP_DIR}/maxmind.tar.gz"
  local extracted

  log "Скачиваю MaxMind ${MAXMIND_EDITION_ID}..."
  curl -fsSL --retry 3 --retry-delay 2 "${mm_url}" -o "${tarball}"

  tar -xzf "${tarball}" -C "${TMP_DIR}"
  extracted="$(ls "${TMP_DIR}"/*/GeoLite2-Country.mmdb 2>/dev/null | head -n 1 || true)"
  if [[ -z "${extracted}" || ! -f "${extracted}" ]]; then
    warn "Не найден GeoLite2-Country.mmdb после распаковки"
    return 1
  fi

  install -m 0644 "${extracted}" "${DATA_DIR}/GeoLite2-Country.mmdb.new"
  mv "${DATA_DIR}/GeoLite2-Country.mmdb.new" "${DATA_DIR}/GeoLite2-Country.mmdb"
  log "MaxMind база обновлена"
}

download_ip2location() {
  local zip_path="${TMP_DIR}/ip2location.zip"
  local bin_path

  log "Скачиваю IP2Location LITE DB1..."
  if ! curl -fsSL --retry 3 --retry-delay 2 "${IP2LOCATION_DB1_URL}" -o "${zip_path}"; then
    warn "Не удалось скачать IP2Location (пропускаю)"
    return 0
  fi

  if ! command -v unzip >/dev/null 2>&1; then
    warn "Команда unzip не установлена, пропускаю IP2Location"
    return 0
  fi

  unzip -o -q "${zip_path}" -d "${TMP_DIR}/ip2"
  bin_path="$(ls "${TMP_DIR}"/ip2/*.BIN 2>/dev/null | head -n 1 || true)"
  if [[ -z "${bin_path}" || ! -f "${bin_path}" ]]; then
    warn "Не найден .BIN в архиве IP2Location"
    return 0
  fi

  install -m 0644 "${bin_path}" "${DATA_DIR}/IP2LOCATION-LITE-DB1.BIN.new"
  mv "${DATA_DIR}/IP2LOCATION-LITE-DB1.BIN.new" "${DATA_DIR}/IP2LOCATION-LITE-DB1.BIN"
  log "IP2Location база обновлена"
}

write_meta() {
  local mm_sha=""; local ip2_sha=""
  [[ -f "${DATA_DIR}/GeoLite2-Country.mmdb" ]] && mm_sha="$(sha256_file "${DATA_DIR}/GeoLite2-Country.mmdb")"
  [[ -f "${DATA_DIR}/IP2LOCATION-LITE-DB1.BIN" ]] && ip2_sha="$(sha256_file "${DATA_DIR}/IP2LOCATION-LITE-DB1.BIN")"

  cat > "${META_FILE}.new" <<EOF
{
  "updated_at_utc": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "maxmind": {
    "edition_id": "${MAXMIND_EDITION_ID}",
    "path": "${DATA_DIR}/GeoLite2-Country.mmdb",
    "sha256": "${mm_sha}"
  },
  "ip2location": {
    "path": "${DATA_DIR}/IP2LOCATION-LITE-DB1.BIN",
    "sha256": "${ip2_sha}"
  }
}
EOF
  mv "${META_FILE}.new" "${META_FILE}"
  log "Метаданные обновлены: ${META_FILE}"
}

download_maxmind
download_ip2location
write_meta

log "Готово"
