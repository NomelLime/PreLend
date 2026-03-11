"""
monitor/daily_digest.py — Ежедневный дайджест в Telegram.

Запускается cron-ом один раз в день (например в 09:00 UTC):
    0 9 * * * /usr/bin/python3 /var/www/prelend/monitor/daily_digest.py

Отчёт содержит (за вчера):
  - Всего кликов / живых / ботов / клоаков
  - Конверсии (ручные + API) и CR
  - Топ-5 ГЕО
  - Топ платформ (youtube / instagram / tiktok / direct)
  - Статус рекламодателей (uptime, Score)
  - Нецелевой трафик %
"""
from __future__ import annotations

import json
import logging
import os
import sqlite3
import sys
import time
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))
from monitor.notifier import send

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [daily_digest] %(levelname)s %(message)s",
)
logger = logging.getLogger(__name__)


def load_json(path: Path) -> dict | list:
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def get_db(db_path: str) -> sqlite3.Connection:
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    return conn


def ts_range(day: date) -> tuple[int, int]:
    """Unix timestamp для начала и конца дня (UTC)."""
    import calendar
    start = calendar.timegm(day.timetuple())
    return start, start + 86399


def run() -> None:
    # PL_DISABLE_DAILY_DIGEST=true → дайджест PreLend отключён,
    # Orchestrator включает эти данные в свой единый суточный дайджест.
    if os.getenv("PL_DISABLE_DAILY_DIGEST", "false").lower() == "true":
        logger.info("daily_digest отключён (PL_DISABLE_DAILY_DIGEST=true)"
                    " — дайджест формирует Orchestrator")
        return

    settings    = load_json(ROOT / "config" / "settings.json")
    advertisers = load_json(ROOT / "config" / "advertisers.json")
    db_path     = str(settings.get("db_path", ROOT / "data" / "clicks.db"))

    conn = get_db(db_path)

    yesterday   = date.today() - timedelta(days=1)
    ts_from, ts_to = ts_range(yesterday)
    day_str     = yesterday.strftime("%d.%m.%Y")

    # ── Клики ─────────────────────────────────────────────────────────────
    row = conn.execute("""
        SELECT
            COUNT(*)                                                AS total,
            SUM(CASE WHEN status = 'sent'    THEN 1 ELSE 0 END)   AS live,
            SUM(CASE WHEN status = 'bot'     THEN 1 ELSE 0 END)   AS bots,
            SUM(CASE WHEN status = 'cloaked' THEN 1 ELSE 0 END)   AS cloaked,
            SUM(CASE WHEN is_test = 1        THEN 1 ELSE 0 END)   AS tests
        FROM clicks
        WHERE ts BETWEEN ? AND ? AND is_test = 0
    """, (ts_from, ts_to)).fetchone()

    total   = row["total"]   or 0
    live    = row["live"]    or 0
    bots    = row["bots"]    or 0
    cloaked = row["cloaked"] or 0

    bot_pct  = round(bots  / total * 100, 1) if total else 0.0
    clk_pct  = round(live  / total * 100, 1) if total else 0.0

    # ── Конверсии ─────────────────────────────────────────────────────────
    conv_row = conn.execute("""
        SELECT COALESCE(SUM(count), 0) AS total_conv
        FROM conversions
        WHERE date = ?
    """, (yesterday.isoformat(),)).fetchone()

    conversions = conv_row["total_conv"] or 0
    cr          = round(conversions / live * 100, 2) if live else 0.0

    # ── Топ-5 ГЕО ─────────────────────────────────────────────────────────
    geo_rows = conn.execute("""
        SELECT geo, COUNT(*) AS cnt
        FROM clicks
        WHERE ts BETWEEN ? AND ? AND status = 'sent' AND is_test = 0
        GROUP BY geo ORDER BY cnt DESC LIMIT 5
    """, (ts_from, ts_to)).fetchall()

    geo_lines = "\n".join(
        f"  {i+1}. <b>{r['geo'] or '??'}</b> — {r['cnt']}"
        for i, r in enumerate(geo_rows)
    ) or "  нет данных"

    # ── Топ платформ ───────────────────────────────────────────────────────
    plat_rows = conn.execute("""
        SELECT platform, COUNT(*) AS cnt
        FROM clicks
        WHERE ts BETWEEN ? AND ? AND status = 'sent' AND is_test = 0
        GROUP BY platform ORDER BY cnt DESC
    """, (ts_from, ts_to)).fetchall()

    plat_icon = {"youtube": "▶️", "instagram": "📸", "tiktok": "🎵",
                 "direct": "🔗", "unknown": "❓"}
    plat_lines = "\n".join(
        f"  {plat_icon.get(r['platform'], '•')} {r['platform']}: {r['cnt']}"
        for r in plat_rows
    ) or "  нет данных"

    # ── Статус рекламодателей ─────────────────────────────────────────────
    adv_lines = []
    for adv in advertisers:
        if adv.get("status") != "active":
            continue
        ls = conn.execute(
            "SELECT is_up, uptime_24h, response_ms FROM landing_status WHERE advertiser_id = ?",
            (adv["id"],)
        ).fetchone()

        if ls:
            flag    = "🟢" if ls["is_up"] else "🔴"
            uptime  = f"{ls['uptime_24h']:.1f}%"
            ms_str  = f"{ls['response_ms']} мс"
        else:
            flag, uptime, ms_str = "⚪", "—", "—"

        adv_lines.append(
            f"  {flag} <b>{adv['name']}</b> | uptime: {uptime} | {ms_str}"
        )

    adv_block = "\n".join(adv_lines) or "  нет данных"

    # ── Нецелевой трафик ──────────────────────────────────────────────────
    offgeo_pct = round((bots + cloaked) / total * 100, 1) if total else 0.0
    offgeo_threshold = int(settings.get("alerts", {}).get("offgeo_pct_per_hour", 60))
    offgeo_warn = f" ⚠️ (порог {offgeo_threshold}%)" if offgeo_pct >= offgeo_threshold else ""

    # ── Сборка сообщения ──────────────────────────────────────────────────
    msg = (
        f"📊 <b>Дайджест PreLend — {day_str}</b>\n"
        f"{'─' * 30}\n\n"
        f"<b>Трафик</b>\n"
        f"  Всего кликов:  <b>{total}</b>\n"
        f"  Живые:         <b>{live}</b> ({clk_pct}%)\n"
        f"  Боты:          {bots} ({bot_pct}%)\n"
        f"  Клоак:         {cloaked}\n\n"
        f"<b>Конверсии</b>\n"
        f"  Конверсий:     <b>{conversions}</b>\n"
        f"  CR:            <b>{cr}%</b>\n\n"
        f"<b>Топ ГЕО</b>\n{geo_lines}\n\n"
        f"<b>Платформы</b>\n{plat_lines}\n\n"
        f"<b>Лендинги</b>\n{adv_block}\n\n"
        f"<b>Нецелевой трафик:</b> {offgeo_pct}%{offgeo_warn}"
    )

    send(msg)
    logger.info("daily_digest отправлен за %s", day_str)
    conn.close()


if __name__ == "__main__":
    run()
