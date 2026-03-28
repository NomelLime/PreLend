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
│   ├── postback.php           # Постбэк конверсий от рекламодателей
│   └── stub_advertiser.php    # Плейсхолдер-лендинг adv_stub; техпанель постбэка: ?debug=1
├── src/
│   ├── BotFilter.php          # 7 фильтров: PASS/BOT/CLOAK/VPN/OFFGEO/OFFHOURS/TOR
│   ├── GeoDetector.php        # CF-IPCountry заголовок → ISO-2 код
│   ├── ClickLogger.php        # INSERT в clicks (SQLite)
│   ├── Router.php             # Score-based выбор рекламодателя (+ кеш shave CR на запрос)
│   ├── Config.php             # Кеш-загрузчик JSON-конфигов
│   ├── DB.php                 # SQLite PDO singleton
│   ├── GeoAdapter.php         # Гео-адаптивный контекст для шаблонов
│   ├── SplitTester.php        # A/B Байесовский split-тест шаблонов
│   ├── TemplateRenderer.php   # Рендер PHP-шаблонов (offer/cloaked)
│   ├── ConversionLogger.php   # Запись конверсий в таблицу conversions
│   ├── PostbackAuth.php       # Глобальный токен постбэка: getenv(PL_POSTBACK_TOKEN) → settings
│   └── SubIdBuilder.php       # Финальный URL с sub_id + utm; utm_content = sp_{stem} (воронка SP→PL)
├── internal_api/              # FastAPI Internal API (NEW — сессия 8)
│   ├── main.py                # FastAPI app, /health endpoint
│   ├── config.py              # Пути, API_KEY, HOST:PORT из env
│   ├── auth.py                # X-API-Key middleware
│   ├── requirements.txt       # fastapi, uvicorn
│   └── routes/
│       ├── metrics.py         # GET /metrics (+ geo_breakdown), /financial, /funnel
│       ├── configs.py         # GET/PUT /config/{settings|advertisers|geo_data|splits}; settings без postback_token в whitelist
│       └── agents.py          # GET /agents, POST /agents/{name}/{stop|start}
├── config/
│   ├── settings.json          # Пороги алертов, cloak_url, redirect_delay_ms (без postback_token в репо — секрет через ENV)
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
    ├── test_conversion_logger.php
    ├── test_content_locale.php
    └── test_postback.php      # PostbackAuth: глобальный токен, приоритет PL_POSTBACK_TOKEN
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

## КЛОАКА: ВИЗУАЛЬНАЯ СТРАНИЦА (LEGEND)

**Где в коде:** HTML/CSS легенды лежат в `templates/cloaked/*.php` (сейчас: `expert_review.php`, `sports_news.php`). Это не отдельный SPA — сервер отдаёт готовый HTML в ответ на тот же URL, что и лендинг (`public/index.php`).

**Какой шаблон:** в ветке `CLOAK` берётся `template` у первого **active** рекламодателя, чьё `geo` пустое или содержит текущее ISO-2; иначе дефолт `expert_review`. Настройка в `config/advertisers.json`.

**Когда показывается:** `BotFilter` возвращает `FilterResult::CLOAK`, если User-Agent содержит подстроку из `PLATFORM_BOTS` в `src/BotFilter.php` (Facebook/Instagram preview, Bytespider/TikTok, Googlebot и др.). Обычный человеческий UA → `PASS` → показывается **оффер** из `templates/offers/`, не клоака.

**Как посмотреть (визуально или сырой HTML):**

| Способ | Действие |
|--------|----------|
| **curl** | `curl -sL -A "facebookexternalhit/1.1" "https://ДОМЕН/"` — без `-A` типичный UA `curl/...` попадает в **GENERIC** ботов → `BOT` → редирект на дефолт, не HTML клоаки. Альтернативы UA: подстрока `Bytespider`, `Googlebot` (см. `PLATFORM_BOTS`). |
| **Chrome / Edge** | F12 → **Network conditions** → снять *Use browser default* → Custom UA, например `facebookexternalhit/1.1` → обновить страницу. |
| **Firefox** | `about:config` → строка `general.useragent.override` = `facebookexternalhit/1.1` → открыть сайт → F5. После проверки — **Сбросить** ключ, иначе везде будешь «ботом». |

---

## INTERNAL API (порт 9090)
Слушает только на `127.0.0.1:9090` — не виден из интернета.
Доступ с локальной машины через SSH tunnel или WireGuard.

| Endpoint                          | Описание |
|-----------------------------------|----------|
| `GET /health`                     | Проверка доступности + `db_exists` |
| `GET /metrics?period_hours=24`    | Клики, CR, bot_pct, top_geo, **geo_breakdown** (клики/conv по ISO-2), shave_suspects, agent_statuses |
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

**Глобальный токен постбэка (`postback.php`):** переменная **`PL_POSTBACK_TOKEN`** должна быть в окружении **PHP-FPM** (не только в `.env` в корне — PHP веб-запросы его не читают автоматически). Пример:
`/etc/php/8.3/fpm/pool.d/www.conf` → `env[PL_POSTBACK_TOKEN] = ...` → `systemctl reload php8.3-fpm`.
Если не задана — используется `postback_token` из `config/settings.json` (fallback). Если токен задан (env или json), параметр запроса **`token`** обязателен и совпадает через `hash_equals` (см. `src/PostbackAuth.php`).
См. также `.env.example` в корне PreLend.

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
[x] Nginx блок `/internal_api` (reference: `deploy/nginx.conf`; на боевом — проверить)
[ ] Настройка SSH tunnel / WireGuard на боевом сервере
[ ] Первый боевой запуск Internal API (если ещё не включён `prelend-internal-api`)

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

### Code Review (18.03.2026) — исправления по результатам полного ревью

| # | Severity | Файл(ы) | Исправление |
|---|----------|---------|-------------|
| FIX#1 | Critical | `src/BotFilter.php`, `tests/test_bot_filter.php` | `DC_SUBNETS` (prefix-matching, ложные срабатывания на /8 блоки) → `DC_CIDRS` с `ip2long()`. 90 точных диапазонов (AWS/GCP/Azure/DO/Hetzner/OVH/Linode). 4 новых теста |
| FIX#3 | High | `public/postback.php` | IP whitelist: `HTTP_X_FORWARDED_FOR` (подделываемый) → `HTTP_CF_CONNECTING_IP` |
| FIX#4 | High | `internal_api/auth.py`, `internal_api/config.py`, `.env.example` | Warning при dev-режиме (один раз за процесс); инструкция по генерации ключа |
| FIX#7 | Medium | `data/init_db.sql` | `idx_conv_rate_limit ON conversions(advertiser_id, source, created_at)` — устраняет full scan при rate-limit проверке |
| FIX#10 | Medium | `src/FilterResult.php` (NEW), `src/BotFilter.php`, `public/index.php`, тесты | PHP 8.1 `enum FilterResult: string`. `BotFilter::check()` → `FilterResult`. Строковые константы оставлены для BC |
| FIX#13 | Low | `src/ClickLogger.php`, `public/index.php` | `log()` возвращает `array{click_id, ok}` вместо строки + side-effect флага `lastInsertFailed` |
| FIX#14 | Low | `src/DB.php` | `SELECT 1` проверка живости PDO-соединения перед переиспользованием singleton |

### Code Review v2 (18.03.2026) — дополнительные исправления после верификации

| # | Severity | Файл(ы) | Исправление |
|---|----------|---------|-------------|
| FIX#17 | Medium | `src/TemplateRenderer.php` | `http_response_code(200)` → `http_response_code(404)` в fallback-ветке отсутствующего шаблона. Поисковики индексировали пустые страницы; мониторинг не замечал проблему |
| BUG-A | Critical | `src/BotFilter.php` | `isVpnOrDatacenter()` читал `$_SERVER['HTTP_CF_CONNECTING_IP']` в runtime → IP терялся при тестировании (после восстановления `$_SERVER`). Исправлено: `$this->ip` кэшируется в `__construct()` |
| BUG-B | High | `src/BotFilter.php` | `checkGeo()` метод отсутствовал → OFFGEO **никогда не возвращался** в продакшне, все пользователи с нецелевым ГЕО получали PASS и попадали на оффер. Добавлен `checkGeo()` + вызов в `check()` перед `checkDevice()` |
| FIX#13b | Medium | `tests/test_bot_filter.php` | `BotFilter::PASS` в assert message → `FilterResult::PASS`. `BotFilter::OFFGEO/OFFHOURS` → `FilterResult::OFFGEO->value / OFFHOURS->value` |

**Статус тестов после всех исправлений:**
- `php tests/test_bot_filter.php` → **27/27** ✅
- `php tests/test_router.php` → **11/11** ✅
- `php tests/test_conversion_logger.php` → **8/8** ✅
- `grep -rn 'BotFilter::PASS|BOT|CLOAK...' src/ public/ tests/` → 0 реальных вхождений ✅


## ЧТО СДЕЛАНО / ЧЕКЛИСТ ДЕПЛОЯ

```
[x] PHP клоакинг (public/index.php, src/*.php)
[x] BotFilter: 7 статусов + FilterResult enum (PHP 8.1 backed enum)
[x] checkGeo() — OFFGEO детекция (фикс: метод отсутствовал, все OFF-GEO пользователи получали PASS)
[x] BotFilter: кеш IP в конструкторе ($this->ip)
[x] OFFHOURS обрабатывается корректно
[x] declare(strict_types=1) во всех PHP файлах
[x] Python-агенты (COMMANDER, ANALYST, MONITOR, OFFER_ROTATOR)
[x] Мониторинг (health_check, shave_detector, daily_digest)
[x] Internal API (FastAPI, порт 9090) — полная реализация
[x] systemd unit для Internal API (deploy/prelend-internal-api.service)
[x] Деплой на pulsority.com (PHP + Nginx + SSL via Certbot)
[x] UTM Nginx rewrites: /t/, /i/, /y/, /go/ (Сессия 12C)
[x] /internal_api заблокирован в nginx (deny all; return 404)
[ ] Запуск Internal API на VPS: systemctl enable --now prelend-internal-api
[ ] Настройка SSH tunnel / WireGuard для Orchestrator↔PreLend
[ ] Verify: curl https://pulsority.com/internal_api/health → 404
[ ] Verify: curl https://pulsority.com/t/test_acc → 200 (UTM rewrites работают)
```

### После деплоя nginx.conf — обязательно

```bash
nginx -t && systemctl reload nginx
# Проверка блокировки Internal API (снаружи):
curl -s -o /dev/null -w "%{http_code}" https://pulsority.com/internal_api/health
# Ожидается: 404  (НЕ 200 или 403 с JSON)

# Проверка UTM bio-ссылок:
curl -s -o /dev/null -w "%{http_code}" https://pulsority.com/t/test_acc
# Ожидается: 200
```

---

### Сессия 13 (21.03.2026) — Стабилизация авторизации Internal API для ContentHub

**Инцидент:**
- ContentHub стабильно стартовал, но при интеграции с PreLend получал `HTTP 403` на `/agents` и `/metrics`.
- Прямой вызов `curl http://localhost:9090/agents` возвращал `{"detail":"Invalid or missing API key"}`.

**Причина:**
- Несовпадение фактического ключа `PL_INTERNAL_API_KEY` в запущенном systemd-процессе `prelend-internal-api` и ключа, который отправлял ContentHub в `X-API-Key`.

**Что сделано на VPS (Ubuntu):**
```bash
sudo nano /etc/systemd/system/prelend-internal-api.service
# Обновлён Environment=PL_INTERNAL_API_KEY=<REAL_KEY>

sudo systemctl daemon-reload
sudo systemctl restart prelend-internal-api
sudo systemctl status prelend-internal-api --no-pager
```

**Проверка:**
```bash
# Без ключа: ожидаемо 403
curl -i http://127.0.0.1:9090/agents

# С ключом: должен быть 200
curl -i -H "X-API-Key: <REAL_KEY>" http://127.0.0.1:9090/agents
```

**Важно для эксплуатации:**
- После любого изменения `PL_INTERNAL_API_KEY` обязателен перезапуск `prelend-internal-api` (systemd).
- На стороне ContentHub тот же ключ должен быть в `backend/.env` (`PL_INTERNAL_API_KEY=...`) и после изменения нужен перезапуск `uvicorn`.

### Сессия 14 (22.03.2026) — Документация: клоака и предпросмотр

- В `status.md` добавлен раздел **«КЛОАКА: ВИЗУАЛЬНАЯ СТРАНИЦА»**: путь к шаблонам `templates/cloaked/`, связь с `advertisers.json`, различие PASS (offers) vs CLOAK (cloaked), инструкции предпросмотра через **curl с платформенным UA**, **Chrome/Edge Network conditions**, **Firefox `general.useragent.override`**.
- Синхронизирован верхний чеклист «СТАТУС РАЗРАБОТКИ» с фактом блокировки `/internal_api` в nginx (см. чеклист деплоя / Сессия 12C).
- Убран дублирующийся заголовок «ЧТО СДЕЛАНО / ЧЕКЛИСТ ДЕПЛОЯ» в середине файла.

### Сессия 15 (22.03.2026) — adv_stub, постбэк-тесты, geo_breakdown в /metrics

| Область | Изменение |
|---------|-----------|
| `config/advertisers.json` | Рекламодатель **`adv_stub`**: низкий `rate`, `subid_param=click_id`, `hmac_secret` для подписи постбэка, `url` на плейсхолдер (прод: `https://pulsority.com/stub_advertiser.php`). |
| `public/stub_advertiser.php` | Публичная заглушка на англ. («Your ad could be here»); HMAC/JSON/форма теста постбэка только с **`?debug=1`**. POST `fire_postback` → редирект на `postback.php` с верным `sig`. |
| `internal_api/routes/metrics.py` | Ответ **`GET /metrics`** дополнен массивом **`geo_breakdown`**: по каждому GEO клики (без bot/cloaked, `is_test=0`), конверсии по `status='converted'`, поле `cr`. |

**Эксплуатация после `git pull` на VPS:**
- Изменения только в `public/*.php` и `config/*.json` → перезапуск не обязателен (PHP-FPM подхватывает файлы).
- Изменения в **`internal_api/`** → **`systemctl restart prelend-internal-api`**, иначе ContentHub/оркестратор не увидят новое поле `geo_breakdown`.

### Сессия 16 (27.03.2026) — Шаблоны в UI, запись конфигов, отсечение техтрафика

| Область | Изменение |
|---------|-----------|
| `internal_api/routes/configs.py` | `GET /templates` — списки имён из `templates/offers/*.php` и `templates/cloaked/*.php`. `PUT /config/{name}` — **универсальный парсер тела**: `application/json` и legacy **multipart/form-data** с полем `body` (совместимость со старым OpenAPI на VPS). |
| `templates/offers/` | Новые шаблоны (в т.ч. `gambling_*`, `betting_*`, `finance_briefing`, `wellness_quiz`, `tech_deals` и др.). |
| `templates/cloaked/` | Новые легенды (`finance_digest`, `health_magazine`, `tech_journal` и др.). |
| `src/FilterResult.php` | Добавлен кейс **`PROBE`** — технический клиент без записи в `clicks`. |
| `src/BotFilter.php` | Список **`TECHNICAL_PROBES`** (curl, wget, kube-probe, типичные uptime-мониторинги и т.д.); проверка после платформенных ботов, до generic-ботов. |
| `public/index.php` | Ветка **`PROBE`**: ответ `200` + `ok`, без `ClickLogger`. |
| `tests/test_bot_filter.php` | `curl` UA ожидает **`PROBE`**, не `BOT`. |

**Деплой на VPS:** `git pull` → `chown` на `config/`, `data/` для пользователя сервиса (часто `www-data`) → `systemctl restart prelend-internal-api` → `systemctl reload php*-fpm` → `systemctl reload nginx`. Проверка: `PUT /config/advertisers` с `Content-Type: application/json` → `{"success":true,...}`.

### Сессия 17 (27.03.2026) — Локализация всех offer-шаблонов + проверка деплоя

| Область | Изменение |
|---------|-----------|
| `src/TemplateI18n.php` | В `pickBundle()` добавлен fallback на `en`: сначала база `en`, затем `array_merge($en, $bundles[$locale])`. Неполные локали автоматически дополняются английскими строками. |
| `templates/i18n/*.json` | Добавлены/обновлены i18n-бандлы для offer-шаблонов: `sports_news`, `tech_deals`, `wellness_quiz`, `finance_briefing`, `betting_parlay_boost`, `betting_live_odds`, `betting_match_center`, `gambling_slot_rush`, `gambling_vip_bonus`, `gambling_lucky_spin` (+ `expert_review` уже был). |
| `templates/offers/*.php` | Все оффер-шаблоны переведены на единый паттерн `$i18n` + `$t()` + `html_lang`; видимые строки берутся из i18n ключей. |
| `templates/cloaked/*` | Не изменялись по требованию; клоака остаётся на английском и отдельной веткой рендера. |
| `public/index.php` + `src/TemplateRenderer.php` | Поток передачи `i18n` в offer-рендер сохранён; локаль продолжает приходить из `ContentLocaleResolver/GeoDetector` контекста. |

**Проверка на боевом сервере (27.03):**
- `curl -sI https://pulsority.com | head -5` → `HTTP/2 200`.
- `php /var/www/prelend/tests/test_content_locale.php` → `5/5 passed`.
- `php /var/www/prelend/tests/test_router.php` → тесты зелёные.

**Эксплуатация после `git pull` для этой сессии:**
- Менялись PHP-шаблоны и i18n JSON → достаточно `systemctl reload php8.3-fpm` (или `restart`, если есть следы кеша).
- `nginx` и `prelend-internal-api` перезапускать не требуется, если их код/конфиги не менялись.

### Сессия 18 (27.03.2026) — Запуск агентов одним скриптом, деплой reload, правка health-check

| Область | Изменение |
|---------|-----------|
| **`start_prelend_agents.py`** (корень репо) | Один процесс: все Python-агенты PreLend (COMMANDER, ANALYST, MONITOR, OFFER_ROTATOR). Запуск на **машине с клоном PreLend** (часто VPS). Без ShortsProject; см. `RUNBOOK_4_PROJECTS.md` п.20–21. |
| **`telegram_router.py` → `PreLendCrew`** | В crew добавлен **OFFER_ROTATOR** (`start`/`stop` вместе с остальными). |
| **`deploy/reload_services.sh`** (NEW) | Один вызов с `sudo`: `nginx reload` → `php8.3-fpm reload` → `systemctl restart prelend-internal-api`. Переменные: `PHP_VER`, `PRELEND_INTERNAL_API_UNIT`, `PRELEND_SKIP_INTERNAL_API=1`. После restart — **повторные попытки** `curl :9090/health` (~5 с), чтобы не было ложного warn из-за гонки с uvicorn. |
| **`deploy/deploy.sh`** | В конце вызывается `reload_services.sh`; в подсказках — `sudo bash …/deploy/reload_services.sh` после `git pull`. |
| **Документация** | `RUNBOOK_4_PROJECTS.md`: п.20 (два launcher-файла агентов, PreLend на другой машине), п.21 (`start_local_stack` на Windows — без PreLend). |

**После обновления кода на VPS:** `git pull` → при необходимости `pip install -r requirements.txt` → `sudo bash /var/www/prelend/deploy/reload_services.sh` (или алиас `prelend-reload`).

### Сессия 19 (28.03.2026) — Code review: безопасность постбэка, DRY клоаки, Router, тесты

| Область | Изменение |
|---------|-----------|
| **`src/PostbackAuth.php`** (NEW) | Проверка глобального токена: `getenv('PL_POSTBACK_TOKEN')` приоритетнее `settings['postback_token']`; если секрет задан, отсутствие верного `token` в запросе → отказ (исправление обхода проверки). |
| **`public/postback.php`** | Подключён `PostbackAuth`; логика токена централизована. |
| **`config/settings.json`** | Удалён захардкоженный `postback_token` из репозитория — в проде задать **`PL_POSTBACK_TOKEN`** в php-fpm. |
| **`internal_api/routes/configs.py`** | Ключ **`postback_token`** убран из whitelist `PUT /config/settings` — нельзя записать секрет в JSON через Internal API. |
| **`public/index.php`** | Вынесена **`resolveCloakTemplate()`** — один выбор шаблона клоаки для CLOAK / OFFGEO / OFFHOURS (DRY). |
| **`src/Router.php`** | Оптимизация shave: кеш медианы CR и карта CR по рекламодателям на один HTTP-запрос (меньше N+1 SQL). |
| **`tests/test_postback.php`** (NEW) | Юнит-тесты `PostbackAuth::globalTokenValid()` (пустой токен, settings, неверный token, приоритет env). |
| **`.env.example`** | Комментарий: как задать `PL_POSTBACK_TOKEN` в php-fpm. |

**Тесты:** `./tests/run_tests.sh` включает `test_postback.php` (CLI `php` не использует php-fpm env — тест env приоритета через `putenv`). Для боевого `postback.php` секрет задаётся в pool php-fpm.

**После деплоя:**
1. Задать в **`/etc/php/8.3/fpm/pool.d/prelend.conf`**: `env[PL_POSTBACK_TOKEN] = ...` и **`systemctl reload php8.3-fpm`** (pool **`[prelend]`**, сокет **`php8.3-fpm-prelend.sock`** — см. `deploy/deploy.sh` / `deploy/nginx.conf`).
2. Убедиться, что постбэк-URL рекламодателя содержит **`token=`** с тем же значением.

### Сессия 20 (28.03.2026) — Деплой: сокет PHP-FPM и 502

| Область | Изменение |
|---------|-----------|
| **`deploy/deploy.sh`** | Пул **`[prelend]`** слушает **`/run/php/php8.3-fpm-prelend.sock`**, не общий `php8.3-fpm.sock` у `www` — иначе два пула на один сокет ломают старт FPM. Nginx `fastcgi_pass` выровнен под тот же путь. Лимиты: **memory_limit 128M**, **max_execution_time 60**; **fastcgi_read_timeout 90s** в блоках PHP. |
| **`deploy/nginx.conf`** | Тот же сокет и таймауты (эталон для ручного копирования). |
| **`.env.example`** | Указан **`pool.d/prelend.conf`** для `env[PL_POSTBACK_TOKEN]`. |

**502 после правок / restart:** проверить **`php-fpm8.3 -t`**, **`systemctl status php8.3-fpm`**, что в **`sites-available`** для сайта **`fastcgi_pass`** совпадает с **`listen =`** в **`prelend.conf`**, и что сокет есть: **`ls -la /run/php/*.sock`**. Править nginx/php-fpm только в конфиг-файлах, не строками `listen=` в интерактивном bash.

**Пошаговая инструкция после `git pull` на уже настроенном VPS** (поиск vhost, правка обоих `fastcgi_pass`, `nginx -t`, проверка curl, альтернатива через `deploy.sh`): **`deploy/UPGRADE_AFTER_GIT_PULL.md`**.

**`status.md` и git:** файл помечен в шапке как локальный журнал сессий; если он в **`.gitignore`** — в remote не уйдёт. Операционные гайды для команды лучше держать в **`deploy/*.md`** (тот же апгрейд-док).
