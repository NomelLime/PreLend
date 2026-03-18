# PreLend — status.md
> Не пушить в гит. Выдавать в чате при старте каждой сессии.

---

## РОЛЬ
PHP-клоакинг лендинг на VPS. Принимает трафик, фильтрует ботов/VPN/off-geo,
роутит живых пользователей на офферы рекламодателей, логирует клики в SQLite.
Управляется удалённо через PreLend Internal API (порт 9090, только localhost).

---

## СТЕК
| Слой          | Технология                                              |
|---------------|---------------------------------------------------------|
| Рантайм       | PHP 8.1+ (все файлы: `declare(strict_types=1)`)         |
| Web-сервер    | Nginx + PHP-FPM                                         |
| БД            | SQLite (`data/clicks.db`)                               |
| Internal API  | Python 3.11+, FastAPI, Uvicorn (порт 9090)              |
| Деплой        | VPS (Linux), systemd unit для Internal API              |

---

## СТРУКТУРА ПРОЕКТА
```
PreLend/
├── public/
│   ├── index.php              # Точка входа: geo → filter → route → log → redirect
│   └── postback.php           # Постбэк конверсий от рекламодателей
├── src/
│   ├── BotFilter.php          # 7 фильтров: PASS/BOT/CLOAK/VPN/OFFGEO/OFFHOURS/TOR
│   ├── GeoDetector.php        # CF-IPCountry заголовок → ISO-2 код
│   ├── ClickLogger.php        # INSERT в clicks (SQLite)
│   ├── Router.php             # Score-based выбор рекламодателя
│   ├── SubIdBuilder.php       # Финальный URL с sub_id + utm-параметрами
│   ├── Config.php             # Кеш-загрузчик JSON-конфигов
│   ├── DB.php                 # SQLite PDO singleton
│   ├── GeoAdapter.php         # Гео-адаптивный контекст для шаблонов
│   ├── SplitTester.php        # A/B Байесовский split-тест шаблонов
│   ├── TemplateRenderer.php   # Рендер PHP-шаблонов (offer/cloaked)
│   ├── ConversionLogger.php   # Запись конверсий в таблицу conversions
│   └── SubIdBuilder.php       # utm_content = sp_{stem} (воронка SP→PL)
├── internal_api/              # FastAPI Internal API (NEW — сессия 8)
│   ├── main.py                # FastAPI app, /health endpoint
│   ├── config.py              # Пути, API_KEY, HOST:PORT из env
│   ├── auth.py                # X-API-Key middleware
│   ├── requirements.txt       # fastapi, uvicorn
│   └── routes/
│       ├── metrics.py         # GET /metrics, /metrics/financial, /metrics/funnel
│       ├── configs.py         # GET/PUT /config/{settings|advertisers|geo_data|splits}
│       └── agents.py          # GET /agents, POST /agents/{name}/{stop|start}
├── config/
│   ├── settings.json          # Пороги алертов, cloak_url, redirect_delay_ms
│   ├── advertisers.json       # Список рекламодателей (id, rate, geo, device, template)
│   ├── geo_data.json          # Гео-контент для шаблонов (город, валюта, язык)
│   └── splits.json            # A/B split-тесты шаблонов
├── data/
│   ├── clicks.db              # SQLite: clicks + conversions таблицы
│   ├── agent_memory.json      # Состояние агентов Python (COMMANDER, ANALYST и др.)
│   └── shave_report.json      # Отчёт монитора о подозрениях на шейв
├── templates/
│   ├── offers/                # PHP-шаблоны офферов (для живых пользователей)
│   └── cloaked/               # PHP-шаблоны легенды (для ботов/сканеров)
├── agents/                    # Python-агенты PreLend (COMMANDER, ANALYST и др.)
├── monitor/                   # Мониторинг: health_check, shave_detector, daily_digest
├── deploy/
│   ├── nginx.conf             # Nginx конфиг (блокирует /internal_api снаружи)
│   ├── deploy.sh              # Скрипт деплоя
│   └── prelend-internal-api.service  # systemd unit для Internal API (NEW)
└── tests/
    ├── test_bot_filter.php    # BotFilter + GeoDetector тесты (вкл. OFFGEO/OFFHOURS)
    ├── test_router.php
    └── test_conversion_logger.php
```

---

## ЛОГИКА index.php
```
1. GeoDetector   → определяем ISO-2 ГЕО из CF-IPCountry
2. BotFilter     → PASS / BOT / CLOAK / VPN / OFFGEO / OFFHOURS / TOR
3. switch:
   CLOAK         → log('cloaked') + renderCloak()
   OFFGEO        → log('cloaked') + renderCloak()  ← добавлено сессия 8
   OFFHOURS      → log('cloaked') + renderCloak()  ← добавлено сессия 8
   BOT/VPN/TOR   → log('bot')    + redirectInstant(defaultOfferUrl)
   PASS (default)→ Router → ClickLogger → SubIdBuilder → TemplateRenderer::renderOffer()
```

---

## INTERNAL API (порт 9090)
Слушает только на `127.0.0.1:9090` — не виден из интернета.
Доступ с локальной машины через SSH tunnel или WireGuard.

| Endpoint                          | Описание |
|-----------------------------------|----------|
| `GET /health`                     | Проверка доступности + `db_exists` |
| `GET /metrics?period_hours=24`    | Клики, CR, bot_pct, top_geo, shave_suspects, agent_statuses |
| `GET /metrics/financial`          | Конверсии с payout (для FinancialObserver) |
| `GET /metrics/funnel`             | Клики по utm_content (для FunnelLinker SP→PL) |
| `GET /config/{name}`              | Чтение: settings / advertisers / geo_data / splits |
| `PUT /config/{name}`              | Атомарная запись + git commit на VPS |
| `GET /agents`                     | Статусы агентов PreLend |
| `POST /agents/{name}/{stop\|start}` | Управление агентами через agent_memory.json |

**Аутентификация:** заголовок `X-API-Key`. Если `PL_INTERNAL_API_KEY` не задан — dev-режим (Swagger UI включён).

---

## ENV ПЕРЕМЕННЫЕ (VPS)
```env
PL_INTERNAL_API_KEY=your-secret-key   # Обязательно в продакшне
PL_INTERNAL_HOST=127.0.0.1            # Только localhost
PL_INTERNAL_PORT=9090
PL_GIT_AUTOCOMMIT=true                # git commit при PUT /config/*
```

---

## ЗАПУСК INTERNAL API
```bash
# Вручную (проверка)
cd /var/www/prelend
PL_INTERNAL_API_KEY=secret uvicorn internal_api.main:app --host 127.0.0.1 --port 9090

# systemd (продакшн)
cp deploy/prelend-internal-api.service /etc/systemd/system/
systemctl enable --now prelend-internal-api

# SSH tunnel с локальной машины
ssh -N -L 9090:127.0.0.1:9090 user@vps-ip
```

---

## СТАТУС РАЗРАБОТКИ

[x] PHP клоакинг (public/index.php, src/*.php)
[x] BotFilter: 7 статусов (PASS/BOT/CLOAK/VPN/OFFGEO/OFFHOURS/TOR)
[x] OFFGEO и OFFHOURS обрабатываются в switch (cloaked)
[x] declare(strict_types=1) во всех PHP файлах
[x] Python-агенты (COMMANDER, ANALYST, MONITOR, OFFER_ROTATOR)
[x] Мониторинг (health_check, shave_detector, daily_digest)
[x] Internal API (FastAPI, порт 9090) — полная реализация
[x] systemd unit для Internal API
[ ] Nginx блок `/internal_api` (добавить в nginx.conf вручную на VPS)
[ ] Настройка SSH tunnel / WireGuard на боевом сервере
[ ] Первый боевой запуск Internal API

---

## ИСТОРИЯ СЕССИЙ

### Сессия 1–7 (до 15.03.2026) — Базовая реализация
PHP клоакинг, BotFilter, Router, агенты, мониторинг.

### Сессия 8 (16.03.2026) — Исправления + Internal API

**Исправления:**

| Файл | Проблема | Исправление |
|------|----------|-------------|
| `public/index.php` | OFFGEO/OFFHOURS не обрабатывались в switch | Добавлены два case до default, логируют 'cloaked', показывают клоак-страницу |
| `src/*.php` (12 файлов) | Отсутствовал `declare(strict_types=1)` | Добавлен во все файлы |
| `tests/test_bot_filter.php` | Не было тестов для OFFGEO/OFFHOURS | Добавлены тест-кейсы |

**Internal API (NEW):**

Реализован `PreLend/internal_api/` — лёгкий FastAPI-сервис для Orchestrator и ContentHub.
Заменяет прямой доступ к файлам PreLend через файловую систему (невозможен, т.к. PreLend на VPS).

- `main.py`, `config.py`, `auth.py`, `routes/metrics.py`, `routes/configs.py`, `routes/agents.py`
- `deploy/prelend-internal-api.service` — systemd unit
- Атомарная запись конфигов (write → rename), git commit на стороне VPS

### Сессия 9 (18.03.2026) — Дельта-ревью + фиксы безопасности Internal API

| Файл | Изменение |
|------|-----------|
| `internal_api/routes/configs.py` | Whitelist 13 ключей для settings; валидация типов advertisers (обязательные поля id/name/status); размерный лимит 1 МБ |
| `internal_api/main.py` | `/health` расширен: `db_size_mb`, `last_click_ago_sec`, `traffic_alive`, `pending_clicks_24h` |

### Сессия 10 (18.03.2026) — BUG-5: ClickLogger lastInsertFailed

| Файл | Проблема | Исправление |
|------|----------|-------------|
| `src/ClickLogger.php` | INSERT fail → мёртвый click_id возвращался вызывающему коду | Добавлен `public bool $lastInsertFailed`; устанавливается в `true` в catch-блоке |
| `public/index.php` | click_id без записи в БД уходил в SubIdBuilder → постбэк без конверсии | После `$logger->log()` проверяем `$logger->lastInsertFailed`; если true — redirect без SubID |
