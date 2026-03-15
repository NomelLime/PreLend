-- PreLend — SQLite schema
-- clicks.db

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ─────────────────────────────────────────
--  clicks
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS clicks (
    click_id        TEXT PRIMARY KEY,          -- UUID v4
    ts              INTEGER NOT NULL,          -- Unix timestamp
    ip              TEXT,
    geo             TEXT,                      -- ISO-2 (от Cloudflare CF-IPCountry)
    device          TEXT,                      -- mobile | desktop | tablet
    platform        TEXT,                      -- youtube | instagram | tiktok | direct | unknown
    advertiser_id   TEXT,
    utm_source      TEXT,
    utm_medium      TEXT,
    utm_campaign    TEXT,
    utm_content     TEXT,
    utm_term        TEXT,
    ua_hash         TEXT,                      -- SHA256(User-Agent)
    referer         TEXT,
    is_test         INTEGER NOT NULL DEFAULT 0, -- 0 | 1
    status          TEXT NOT NULL DEFAULT 'sent' -- sent | converted | bot | cloaked
);

CREATE INDEX IF NOT EXISTS idx_clicks_ts            ON clicks(ts);
CREATE INDEX IF NOT EXISTS idx_clicks_advertiser     ON clicks(advertiser_id);
CREATE INDEX IF NOT EXISTS idx_clicks_geo            ON clicks(geo);
CREATE INDEX IF NOT EXISTS idx_clicks_is_test        ON clicks(is_test);

-- ─────────────────────────────────────────
--  conversions
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS conversions (
    conv_id         TEXT PRIMARY KEY,          -- UUID v4
    date            TEXT NOT NULL,             -- YYYY-MM-DD (дата конверсии у рекламодателя)
    advertiser_id   TEXT NOT NULL,
    count           INTEGER NOT NULL DEFAULT 1,
    source          TEXT NOT NULL DEFAULT 'manual', -- manual | api
    notes           TEXT,
    created_at      INTEGER NOT NULL           -- Unix timestamp вставки
);

CREATE INDEX IF NOT EXISTS idx_conv_date        ON conversions(date);
CREATE INDEX IF NOT EXISTS idx_conv_advertiser  ON conversions(advertiser_id);

-- ─────────────────────────────────────────
--  advertiser_rates  (история изменений ставок)
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS advertiser_rates (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    advertiser_id   TEXT NOT NULL,
    rate            REAL NOT NULL,
    geo             TEXT,                      -- NULL = глобальная ставка
    changed_at      INTEGER NOT NULL,          -- Unix timestamp
    changed_by      TEXT NOT NULL DEFAULT 'manual' -- manual | commander
);

CREATE INDEX IF NOT EXISTS idx_rates_advertiser ON advertiser_rates(advertiser_id);

-- ─────────────────────────────────────────
--  landing_status  (uptime мониторинг)
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS landing_status (
    advertiser_id   TEXT PRIMARY KEY,
    last_check      INTEGER,                   -- Unix timestamp
    response_ms     INTEGER,
    is_up           INTEGER NOT NULL DEFAULT 1, -- 0 | 1
    uptime_24h      REAL NOT NULL DEFAULT 100.0 -- процент за 24 часа
);

-- ─────────────────────────────────────────
--  split_results  (A/B split-test results)
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS split_results (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    ts              INTEGER NOT NULL,              -- Unix timestamp события
    split_id        TEXT NOT NULL,                 -- ID теста из splits.json
    variant_id      TEXT NOT NULL,                 -- ID варианта ('var_a', 'var_b', ...)
    geo             TEXT,                          -- ISO-2
    click_id        TEXT,                          -- FK к clicks.click_id
    converted       INTEGER NOT NULL DEFAULT 0     -- 0 | 1
);

CREATE INDEX IF NOT EXISTS idx_split_results_split   ON split_results(split_id);
CREATE INDEX IF NOT EXISTS idx_split_results_variant ON split_results(split_id, variant_id);
CREATE INDEX IF NOT EXISTS idx_split_results_ts      ON split_results(ts);
