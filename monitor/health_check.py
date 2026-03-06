"""
monitor/health_check.py — Мониторинг доступности лендингов рекламодателей.

Запускается cron-ом каждые 5 минут:
    */5 * * * * /usr/bin/python3 /var/www/prelend/monitor/health_check.py

Что делает:
  1. Читает всех активных рекламодателей из advertisers.json
  2. Делает HEAD-запрос на URL каждого лендинга (таймаут 8 сек)
  3. Обновляет таблицу landing_status в SQLite (is_up, response_ms, uptime_24h)
  4. Отправляет Telegram-алерты:
       🔴 Лендинг упал           (is_up 1→0)
       🟢 Лендинг восстановлен   (is_up 0→1)
       🟡 Лендинг медленно отвечает (>2000 мс)
"""
from __future__ import annotations

import json
import logging
import os
import sqlite3
import sys
import time
from pathlib import Path

import requests

# ── Пути ─────────────────────────────────────────────────────────────────────
ROOT     = Path(__file__).resolve().parent.parent
CFG_ADV  = ROOT / "config" / "advertisers.json"
CFG_SET  = ROOT / "config" / "settings.json"

sys.path.insert(0, str(ROOT))
from monitor.notifier import alert

# ── Логирование ───────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [health_check] %(levelname)s %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger(__name__)


def load_json(path: Path) -> dict | list:
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def get_db(db_path: str) -> sqlite3.Connection:
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    return conn


def check_landing(url: str, timeout: int = 8) -> tuple[bool, int]:
    """
    HEAD-запрос на URL. Возвращает (is_up, response_ms).
    Считаем 'up' при статусе < 500.
    """
    try:
        start = time.time()
        resp  = requests.head(url, timeout=timeout, allow_redirects=True,
                              headers={"User-Agent": "PreLend-Monitor/1.0"})
        ms    = int((time.time() - start) * 1000)
        is_up = resp.status_code < 500
        return is_up, ms
    except requests.Timeout:
        return False, timeout * 1000
    except Exception as exc:
        logger.warning("Ошибка запроса %s: %s", url, exc)
        return False, -1


def calc_uptime_24h(conn: sqlite3.Connection, adv_id: str) -> float:
    """
    Рассчитываем uptime за последние 24 часа на основе истории проверок.
    Берём текущее is_up из landing_status (простое скользящее среднее —
    достаточно для MVP, в продакшне можно добавить таблицу check_log).
    """
    row = conn.execute(
        "SELECT is_up, uptime_24h FROM landing_status WHERE advertiser_id = ?",
        (adv_id,)
    ).fetchone()

    if row is None:
        return 100.0

    prev_uptime = float(row["uptime_24h"])
    # Сглаживаем: каждая проверка весит 1/288 суток (5 мин интервал)
    weight  = 1 / 288
    new_val = prev_uptime * (1 - weight) + (100.0 if row["is_up"] else 0.0) * weight
    return round(new_val, 2)


def update_status(conn: sqlite3.Connection, adv_id: str,
                  is_up: bool, response_ms: int, uptime_24h: float) -> None:
    conn.execute("""
        INSERT INTO landing_status (advertiser_id, last_check, response_ms, is_up, uptime_24h)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(advertiser_id) DO UPDATE SET
            last_check  = excluded.last_check,
            response_ms = excluded.response_ms,
            is_up       = excluded.is_up,
            uptime_24h  = excluded.uptime_24h
    """, (adv_id, int(time.time()), response_ms, int(is_up), uptime_24h))
    conn.commit()


def get_prev_status(conn: sqlite3.Connection, adv_id: str) -> bool | None:
    row = conn.execute(
        "SELECT is_up FROM landing_status WHERE advertiser_id = ?", (adv_id,)
    ).fetchone()
    return bool(row["is_up"]) if row else None


def run() -> None:
    settings    = load_json(CFG_SET)
    advertisers = load_json(CFG_ADV)
    db_path     = str(settings.get("db_path", ROOT / "data" / "clicks.db"))
    slow_ms     = int(settings.get("alerts", {}).get("landing_slow_ms", 2000))

    conn = get_db(db_path)

    for adv in advertisers:
        if adv.get("status") != "active":
            continue

        adv_id   = adv["id"]
        adv_name = adv["name"]
        url      = adv["url"]

        prev_up = get_prev_status(conn, adv_id)
        is_up, ms = check_landing(url)
        uptime_24h = calc_uptime_24h(conn, adv_id)

        update_status(conn, adv_id, is_up, ms, uptime_24h)

        logger.info("[%s] %s | is_up=%s | %dms | uptime=%.1f%%",
                    adv_id, adv_name, is_up, ms, uptime_24h)

        # ── Алерты ────────────────────────────────────────────────────
        if not is_up and (prev_up is None or prev_up):
            alert(
                f"🔴 <b>Лендинг упал!</b>\n"
                f"Рекламодатель: <b>{adv_name}</b> (<code>{adv_id}</code>)\n"
                f"URL: {url}\n"
                f"Uptime 24h: {uptime_24h:.1f}%"
            )

        elif is_up and prev_up is False:
            alert(
                f"🟢 <b>Лендинг восстановлен</b>\n"
                f"Рекламодатель: <b>{adv_name}</b> (<code>{adv_id}</code>)\n"
                f"Время ответа: {ms} мс"
            )

        elif is_up and ms > slow_ms:
            alert(
                f"🟡 <b>Лендинг медленно отвечает</b>\n"
                f"Рекламодатель: <b>{adv_name}</b> (<code>{adv_id}</code>)\n"
                f"Время ответа: <b>{ms} мс</b> (порог: {slow_ms} мс)"
            )

    conn.close()
    logger.info("health_check завершён")


if __name__ == "__main__":
    run()
