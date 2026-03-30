# Автообновление GEO-базы (IP2Location)

В проекте используется цепочка:

1. `IP2LOCATION-LITE-DB1.BIN` (IP2Location, при наличии PHP-библиотеки)
2. rescue по `Accept-Language`

## Что уже настроено

- Скрипт обновления: `deploy/update_geo_bases.sh`
- Cron (в `deploy/deploy.sh`): каждый понедельник `03:20 UTC`
- Лог: `logs/geo_bases_update.log`
- Метаданные последнего обновления: `data/geo_bases_meta.json`
- Composer-пакет для PHP (опционально): `ip2location/ip2location-php`

## Переменные окружения

- `IP2LOCATION_DB1_URL` (по умолчанию LITE DB1 ZIP)
- `PRELEND_WEBROOT` (по умолчанию `/var/www/prelend`)

## Ручной запуск

```bash
cd /var/www/prelend
bash deploy/update_geo_bases.sh
```

## Важно по IP2Location в PHP

Для fallback в `GeoAdapter` нужна библиотека `IP2Location\Database`.
Если библиотека не установлена, код автоматически пропускает IP2Location и переходит к следующему шагу.
