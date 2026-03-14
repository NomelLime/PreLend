"""
monitor/shave_detector.py — Детектор шейва + интеграция с ANALYST.

Запуск:
  cron ежедневно:
    0 10 * * * python3 /var/www/prelend/monitor/shave_detector.py

  С авто-запуском ANALYST при обнаружении шейва:
    0 10 * * * python3 /var/www/prelend/monitor/shave_detector.py --analyst

  Принудительный полный отчёт (даже если шейва нет):
    python3 monitor/shave_detector.py --analyst --force

Пайплайн:
  run()
    → collect_cr_data()     — CR + конверсии по источникам и дням
    → calculate_shave()     — shave_coef vs медиана по ГЕО
    → detect_patterns()     — паттерны: no_api / dropoff / low_api_ratio
    → shave_report.json     — сохраняем для ANALYST
    → Telegram алерт 🚨 при подозрении
    → --analyst → Analyst.analyze() → Ollama вердикт → Telegram отчёт
"""
from __future__ import annotations

import argparse
import calendar
import json
import logging
import sqlite3
import sys
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from monitor.notifier import alert

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [shave_detector] %(levelname)s %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger(__name__)

SHAVE_REPORT_FILE = ROOT / "data" / "shave_report.json"
ANALYSIS_DAYS     = 7
MIN_CLICKS        = 30


# ── Утилиты ───────────────────────────────────────────────────────────────────

def load_json(path: Path) -> dict | list:
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def get_db(db_path: str) -> sqlite3.Connection:
    conn = sqlite3.connect(db_path, timeout=5.0)
    conn.row_factory = sqlite3.Row
    return conn


def median(values: list[float]) -> float:
    if not values:
        return 0.0
    s = sorted(values)
    n = len(s)
    return s[n // 2] if n % 2 else (s[n // 2 - 1] + s[n // 2]) / 2


# ── Сбор данных ───────────────────────────────────────────────────────────────

def collect_cr_data(
    conn: sqlite3.Connection,
    adv_map: dict,
    ts_since: int,
) -> tuple[dict, dict]:
    """
    Возвращает (report, cr_by_geo).
    report[adv_id] = {name, clicks, convs, cr, geo_key,
                      conv_by_src, conv_by_day}
    """
    report: dict = {}
    cr_by_geo: dict[str, list[float]] = {}

    for adv_id, adv in adv_map.items():
        click_row = conn.execute("""
            SELECT COUNT(*) AS cnt FROM clicks
            WHERE advertiser_id = ? AND ts >= ? AND status = 'sent' AND is_test = 0
        """, (adv_id, ts_since)).fetchone()

        conv_row = conn.execute("""
            SELECT COALESCE(SUM(count), 0) AS cnt FROM conversions
            WHERE advertiser_id = ? AND created_at >= ?
        """, (adv_id, ts_since)).fetchone()

        src_rows = conn.execute("""
            SELECT source, COALESCE(SUM(count), 0) AS cnt FROM conversions
            WHERE advertiser_id = ? AND created_at >= ?
            GROUP BY source
        """, (adv_id, ts_since)).fetchall()

        daily_rows = conn.execute("""
            SELECT date, COALESCE(SUM(count), 0) AS cnt FROM conversions
            WHERE advertiser_id = ? AND created_at >= ?
            GROUP BY date ORDER BY date
        """, (adv_id, ts_since)).fetchall()

        clicks  = click_row["cnt"] or 0
        convs   = conv_row["cnt"]  or 0
        cr      = convs / clicks if clicks >= MIN_CLICKS else None
        geos    = adv.get("geo", [])
        geo_key = ",".join(sorted(geos)) if geos else "GLOBAL"

        report[adv_id] = {
            "name":        adv["name"],
            "clicks":      clicks,
            "convs":       convs,
            "cr":          cr,
            "geo_key":     geo_key,
            "conv_by_src": {r["source"]: r["cnt"] for r in src_rows},
            "conv_by_day": {r["date"]: r["cnt"] for r in daily_rows},
        }

        if cr is not None:
            cr_by_geo.setdefault(geo_key, []).append(cr)

    return report, cr_by_geo


# ── Расчёт шейва ──────────────────────────────────────────────────────────────

def calculate_shave(
    report: dict,
    cr_by_geo: dict,
    threshold: float,
) -> tuple[dict, list]:
    """Считает shave_coef, возвращает (report, suspects)."""
    suspects = []

    for adv_id, data in report.items():
        cr = data["cr"]

        if cr is None:
            data["shave_coef"] = None
            data["verdict"]    = "insufficient_data"
            continue

        geo_key  = data["geo_key"]
        all_cr   = cr_by_geo.get(geo_key, [])

        if len(all_cr) < 2:
            data["shave_coef"] = 0.0
            data["median_cr"]  = cr
            data["verdict"]    = "no_peers"
            continue

        med        = median(all_cr)
        shave_coef = max(0.0, (med - cr) / med) if med > 0 else 0.0

        data["shave_coef"] = round(shave_coef, 4)
        data["median_cr"]  = round(med, 4)
        data["verdict"]    = "shave_suspected" if shave_coef >= threshold else "ok"

        if shave_coef >= threshold:
            suspects.append((adv_id, data))

    return report, suspects


# ── Паттерны конверсий ────────────────────────────────────────────────────────

def detect_conversion_patterns(report: dict) -> dict:
    """
    Дополнительные сигналы шейва:
      no_api_conversions — есть manual-конверсии, нет api (рекл. не засчитывает)
      conversion_dropoff — резкий обрыв конверсий за последние 3 дня
      low_api_ratio      — менее 10% конверсий пришло через api
    """
    patterns: dict = {}

    for adv_id, data in report.items():
        flags = []
        src   = data.get("conv_by_src", {})
        daily = data.get("conv_by_day", {})
        total = data["convs"]

        if src.get("manual", 0) > 0 and src.get("api", 0) == 0 and total > 0:
            flags.append("no_api_conversions")

        if daily:
            from datetime import date as _date, timedelta
            recent_dates = {(_date.today() - timedelta(days=i)).isoformat() for i in range(3)}
            recent_convs = sum(daily.get(d, 0) for d in recent_dates)
            if total > 5 and recent_convs == 0:
                flags.append("conversion_dropoff")

        if total >= 10 and (src.get("api", 0) / total) < 0.1:
            flags.append("low_api_ratio")

        patterns[adv_id] = flags

    return patterns


# ── ANALYST интеграция ────────────────────────────────────────────────────────

def run_analyst(force: bool = False) -> str:
    """Запускает Analyst.analyze() напрямую (без фонового потока)."""
    try:
        from agents.analyst import Analyst
        from agents.memory  import get_memory

        analyst = Analyst(memory=get_memory())
        result  = analyst.analyze(force_report=force)
        logger.info("[shave_detector] ANALYST завершил анализ")
        return result
    except Exception as exc:
        msg = f"ANALYST ошибка: {exc}"
        logger.error("[shave_detector] %s", msg)
        return msg


# ── Главная функция ───────────────────────────────────────────────────────────

def run(trigger_analyst: bool = False, force: bool = False) -> None:
    settings    = load_json(ROOT / "config" / "settings.json")
    advertisers = load_json(ROOT / "config" / "advertisers.json")
    db_path     = str(settings.get("db_path", ROOT / "data" / "clicks.db"))
    threshold   = float(settings.get("alerts", {}).get("shave_threshold_pct", 25)) / 100

    conn     = get_db(db_path)
    ts_since = calendar.timegm(
        (date.today() - timedelta(days=ANALYSIS_DAYS)).timetuple()
    )
    adv_map = {a["id"]: a for a in advertisers if a.get("status") == "active"}

    # 1. Сбор данных
    report, cr_by_geo = collect_cr_data(conn, adv_map, ts_since)
    conn.close()

    # 2. Расчёт шейва
    report, suspects = calculate_shave(report, cr_by_geo, threshold)

    # 3. Паттерны конверсий
    patterns = detect_conversion_patterns(report)
    for adv_id, flags in patterns.items():
        if flags:
            report[adv_id]["pattern_flags"] = flags

    # 4. Сохраняем shave_report.json
    SHAVE_REPORT_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(SHAVE_REPORT_FILE, "w", encoding="utf-8") as f:
        json.dump({
            "generated_at":  date.today().isoformat(),
            "analysis_days": ANALYSIS_DAYS,
            "threshold":     threshold,
            "report":        report,
        }, f, ensure_ascii=False, indent=2)

    logger.info("shave_report.json сохранён (%d рекламодателей)", len(report))

    # 5. Telegram алерты — шейв
    for adv_id, data in suspects:
        flags = patterns.get(adv_id, [])
        flag_str = ("\n🔎 Паттерны: " + ", ".join(flags)) if flags else ""
        analyst_note = "🤖 Запускаю ANALYST..." if trigger_analyst else \
                       "➡️ /prelend analyst для детального разбора"
        alert(
            f"🚨 <b>Подозрение на шейв!</b>\n"
            f"Рекламодатель: <b>{data['name']}</b> (<code>{adv_id}</code>)\n"
            f"CR рекл.:   <b>{data['cr']:.2%}</b>\n"
            f"Медиана CR: {data['median_cr']:.2%}\n"
            f"Shave-коэф: <b>{data['shave_coef']:.1%}</b> (порог {threshold:.0%})\n"
            f"Период: {ANALYSIS_DAYS} дней{flag_str}\n\n"
            f"{analyst_note}"
        )

    # 6. Telegram алерты — паттерны (без явного шейва)
    for adv_id, flags in patterns.items():
        if not flags:
            continue
        if report[adv_id].get("verdict") == "shave_suspected":
            continue  # уже включено в алерт выше
        data = report[adv_id]
        cr_s = f"{data['cr']:.2%}" if data.get("cr") is not None else "н/д"
        alert(
            f"⚠️ <b>Подозрительные паттерны конверсий</b>\n"
            f"Рекламодатель: <b>{data['name']}</b> (<code>{adv_id}</code>)\n"
            f"CR: {cr_s}\n"
            f"Флаги: {', '.join(flags)}"
        )

    # 7. Лог-итог
    summary_lines = []
    for aid, d in report.items():
        icon   = "🚨" if d["verdict"] == "shave_suspected" else \
                 "⚠️" if patterns.get(aid) else "✅"
        cr_s   = f"{d['cr']:.2%}" if d.get("cr") is not None else "н/д"
        sc_s   = f"{d['shave_coef']:.1%}" if d.get("shave_coef") is not None else "н/д"
        summary_lines.append(
            f"  {icon} {d['name']}: CR={cr_s}, shave={sc_s}, verdict={d['verdict']}"
        )
    logger.info("Результаты:\n%s", "\n".join(summary_lines))

    if not suspects:
        logger.info("Подозрений нет")

    # 8. Запуск ANALYST
    if trigger_analyst and (suspects or force):
        logger.info("Запускаю ANALYST...")
        run_analyst(force=force)
    elif force and trigger_analyst:
        run_analyst(force=True)


# ── CLI ───────────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(description="PreLend Shave Detector")
    parser.add_argument("--analyst", action="store_true",
                        help="Запустить ANALYST после обнаружения подозрений")
    parser.add_argument("--force",   action="store_true",
                        help="Принудительный отчёт ANALYST даже без шейва")
    args = parser.parse_args()
    run(trigger_analyst=args.analyst, force=args.force)


if __name__ == "__main__":
    main()
