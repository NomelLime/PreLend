# Автообновление GEO-баз (MaxMind + IP2Location)

В проекте используется цепочка:

1. `GeoLite2-Country.mmdb` (MaxMind)
2. `IP2LOCATION-LITE-DB1.BIN` (IP2Location, при наличии PHP-библиотеки)
3. rescue по `Accept-Language`

## Что уже настроено

- Скрипт обновления: `deploy/update_geo_bases.sh`
- Cron (в `deploy/deploy.sh`): каждый понедельник `03:20 UTC`
- Лог: `logs/geo_bases_update.log`
- Метаданные последнего обновления: `data/geo_bases_meta.json`
- Composer-пакеты для PHP: `maxmind-db/reader`, `ip2location/ip2location-php`

## Обязательные переменные окружения

- `MAXMIND_LICENSE_KEY` — ключ MaxMind (для скачивания GeoLite2)

Опционально:

- `MAXMIND_EDITION_ID` (по умолчанию `GeoLite2-Country`)
- `IP2LOCATION_DB1_URL` (по умолчанию LITE DB1 ZIP)
- `PRELEND_WEBROOT` (по умолчанию `/var/www/prelend`)

## Ручной запуск

```bash
cd /var/www/prelend
MAXMIND_LICENSE_KEY=xxxx bash deploy/update_geo_bases.sh
```

## Важно по IP2Location в PHP

Для fallback в `GeoAdapter` нужна библиотека `IP2Location\Database`.
Если библиотека не установлена, код автоматически пропускает IP2Location и переходит к следующему шагу.
