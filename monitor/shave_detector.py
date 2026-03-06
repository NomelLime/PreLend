"""
monitor/shave_detector.py — Детектор шейва (расхождения CR).

Запускается cron-ом 1 раз в день (например в 10:00):
    0 10 * * * /usr/bin/python3 /var/www/prelend/monitor/shave_detector.py

Алгоритм:
  1. За последние N дней считаем CR каждого рекламодателя:
       CR = конверсии / живые клики
  2. Считаем медиану CR по всем рекламодателям для данного ГЕО
  3. Shave_коэф = (median_CR - adv_CR) / median_CR
  4. Если Shave_коэф > порога из settings.json → алерт 🚨

Результаты сохраняются в data/shave_report.json для ANALYST-агента.
"""
from __future__ import annotations

import json
import logging
import sqlite3
import sys
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))
from monitor.notifier import alert, send

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [shave_detector] %(levelname)s %(message)s",
)
logger = logging.getLogger(__name__)

SHAVE_REPORT_FILE = ROOT / "data" / "shave_report.json"
ANALYSIS_DAYS     = 7   # окно анализа в днях


def load_json(path: Path) -> dict | list:
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def get_db(db_path: str) -> sqlite3.Connection:
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    return conn


def median(values: list[float]) -> float:
    if not values:
        return 0.0
    s = sorted(values)
    n = len(s)
    return (s[n // 2] if n % 2 else (s[n // 2 - 1] + s[n // 2]) / 2)


def run() -> None:
    settings    = load_json(ROOT / "config" / "settings.json")
    advertisers = load_json(ROOT / "config" / "advertisers.json")
    db_path     = str(settings.get("db_path", ROOT / "data" / "clicks.db"))
    threshold   = float(settings.get("alerts", {}).get("shave_threshold_pct", 25)) / 100

    conn     = get_db(db_path)
    since_dt = date.today() - timedelta(days=ANALYSIS_DAYS)

    import calendar
    ts_since = calendar.timegm(since_dt.timetuple())

    adv_map   = {a["id"]: a for a in advertisers if a.get("status") == "active"}
    report    = {}
    cr_by_geo: dict[str, list[float]] = {}   # geo → [cr, ...]

    # ── Сбор CR по рекламодателям ─────────────────────────────────────────
    for adv_id, adv in adv_map.items():
        click_row = conn.execute("""
            SELECT COUNT(*) AS cnt FROM clicks
            WHERE advertiser_id = ? AND ts >= ? AND status = 'sent' AND is_test = 0
        """, (adv_id, ts_since)).fetchone()

        conv_row = conn.execute("""
            SELECT COALESCE(SUM(count), 0) AS cnt FROM conversions
            WHERE advertiser_id = ? AND created_at >= ?
        """, (adv_id, ts_since)).fetchone()

        clicks  = click_row["cnt"] or 0
        convs   = conv_row["cnt"]  or 0
        cr      = convs / clicks if clicks >= 30 else None  # минимум 30 кликов

        geos = adv.get("geo", [])
        geo_key = ",".join(sorted(geos)) if geos else "GLOBAL"

        report[adv_id] = {
            "name":    adv["name"],
            "clicks":  clicks,
            "convs":   convs,
            "cr":      cr,
            "geo_key": geo_key,
        }

        if cr is not None:
            cr_by_geo.setdefault(geo_key, []).append(cr)

    # ── Расчёт шейва ──────────────────────────────────────────────────────
    alerts_sent = []

    for adv_id, data in report.items():
        cr = data["cr"]
        if cr is None:
            data["shave_coef"] = None
            data["verdict"]    = "insufficient_data"
            continue

        geo_key    = data["geo_key"]
        peers_cr   = [c for c in cr_by_geo.get(geo_key, []) if c != cr]

        if not peers_cr:
            data["shave_coef"] = 0.0
            data["verdict"]    = "no_peers"
            continue

        med        = median(peers_cr + [cr])
        shave_coef = max(0.0, (med - cr) / med) if med > 0 else 0.0

        data["shave_coef"]   = round(shave_coef, 4)
        data["median_cr"]    = round(med, 4)

        if shave_coef >= threshold:
            data["verdict"] = "shave_suspected"
            alerts_sent.append((adv_id, data))
            logger.warning("[%s] Подозрение на шейв: coef=%.2f (порог %.2f)",
                           adv_id, shave_coef, threshold)
        else:
            data["verdict"] = "ok"

    # ── Сохраняем отчёт для ANALYST ───────────────────────────────────────
    SHAVE_REPORT_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(SHAVE_REPORT_FILE, "w", encoding="utf-8") as f:
        json.dump({"generated_at": date.today().isoformat(), "report": report}, f,
                  ensure_ascii=False, indent=2)

    conn.close()

    # ── Telegram алерты ───────────────────────────────────────────────────
    for adv_id, data in alerts_sent:
        alert(
            f"🚨 <b>Подозрение на шейв!</b>\n"
            f"Рекламодатель: <b>{data['name']}</b> (<code>{adv_id}</code>)\n"
            f"CR рекл.:   <b>{data['cr']:.2%}</b>\n"
            f"Медиана CR: {data['median_cr']:.2%}\n"
            f"Shave-коэф: <b>{data['shave_coef']:.1%}</b> (порог {threshold:.0%})\n"
            f"Период: последние {ANALYSIS_DAYS} дней\n\n"
            f"Запусти /prelend analyst для детального разбора."
        )

    if not alerts_sent:
        logger.info("shave_detector: всё в норме, алертов нет")

    # Итоговый summary в лог (без спама в Telegram если норма)
    summary = "\n".join(
        f"  {'🚨' if d['verdict'] == 'shave_suspected' else '✅'} {d['name']}: "
        f"CR={d.get('cr', 'n/a')}, shave={d.get('shave_coef', 'n/a')}"
        for d in report.values()
    )
    logger.info("Результаты:\n%s", summary)


if __name__ == "__main__":
    run()
