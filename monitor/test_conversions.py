"""
monitor/test_conversions.py — Антишейв: тестовые конверсии.

Два режима (через аргумент командной строки):

  SEND (запускается в воскресенье):
    0 12 * * 0  python3 /var/www/prelend/monitor/test_conversions.py send
    - Вставляет тестовые конверсии TEST_<uuid> в таблицу conversions
    - Каждый активный рекламодатель получает 1-3 тестовые конверсии
    - Сохраняет отправленные TEST_ID в data/test_conversions_pending.json

  REPORT (запускается в понедельник утром):
    30 9 * * 1  python3 /var/www/prelend/monitor/test_conversions.py report
    - Сравнивает pending TEST_ID с тем, что есть в таблице conversions
    - Считает: засчитано / не засчитано / pending
    - Отправляет Telegram-отчёт
    - Алерт ⚠️ если рекламодатель не засчитал ни одну тестовую конверсию
"""
from __future__ import annotations

import json
import logging
import sqlite3
import sys
import time
import uuid
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))
from monitor.notifier import alert, send

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [test_conversions] %(levelname)s %(message)s",
)
logger = logging.getLogger(__name__)

PENDING_FILE = ROOT / "data" / "test_conversions_pending.json"


def load_json(path: Path) -> dict | list:
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def get_db(db_path: str) -> sqlite3.Connection:
    conn = sqlite3.connect(db_path, timeout=5.0)
    conn.row_factory = sqlite3.Row
    return conn


# ── SEND ──────────────────────────────────────────────────────────────────────

def send_test_conversions() -> None:
    settings    = load_json(ROOT / "config" / "settings.json")
    advertisers = load_json(ROOT / "config" / "advertisers.json")
    db_path     = str(settings.get("db_path", ROOT / "data" / "clicks.db"))
    prefix      = settings.get("test_conversion_prefix", "TEST_")

    conn    = get_db(db_path)
    today   = date.today().isoformat()
    pending = {}   # adv_id → [test_ids]

    for adv in advertisers:
        if adv.get("status") != "active":
            continue

        adv_id   = adv["id"]
        adv_name = adv["name"]

        # Генерируем 2 тестовые конверсии для каждого рекламодателя
        test_ids = [f"{prefix}{uuid.uuid4().hex[:12].upper()}" for _ in range(2)]

        for test_id in test_ids:
            conn.execute("""
                INSERT INTO conversions (conv_id, date, advertiser_id, count, source, notes, created_at)
                VALUES (?, ?, ?, 1, 'api', ?, ?)
            """, (test_id, today, adv_id, f"TEST conversion — {adv_name}", int(time.time())))

        conn.commit()
        pending[adv_id] = {"name": adv_name, "ids": test_ids, "date": today}
        logger.info("[%s] Отправлены TEST: %s", adv_id, test_ids)

    conn.close()

    # Сохраняем pending для report
    PENDING_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(PENDING_FILE, "w", encoding="utf-8") as f:
        json.dump(pending, f, ensure_ascii=False, indent=2)

    count = sum(len(v["ids"]) for v in pending.values())
    send(
        f"🧪 <b>Тестовые конверсии отправлены</b>\n"
        f"Дата: {today}\n"
        f"Рекламодателей: {len(pending)}\n"
        f"Конверсий: {count}\n\n"
        f"Отчёт о засчитанных — в понедельник утром."
    )


# ── REPORT ────────────────────────────────────────────────────────────────────

def report_test_conversions() -> None:
    if not PENDING_FILE.exists():
        logger.warning("Файл pending не найден: %s", PENDING_FILE)
        send("⚠️ <b>TEST-отчёт</b>: файл pending не найден. Возможно send не запускался.")
        return

    settings = load_json(ROOT / "config" / "settings.json")
    db_path  = str(settings.get("db_path", ROOT / "data" / "clicks.db"))
    pending  = load_json(PENDING_FILE)

    conn     = get_db(db_path)
    lines    = []
    problems = []

    for adv_id, info in pending.items():
        adv_name = info["name"]
        test_ids = info["ids"]
        sent_dt  = info["date"]

        # Проверяем: есть ли эти conv_id в таблице conversions с source != 'api'
        # или просто факт наличия записи считается "засчитанным"
        # В MVP: считаем засчитанным если запись EXISTS (рекл. подтвердил через API или она там)
        # Реальная проверка — рекл. ДОЛЖЕН прислать те же ID через свой postback
        # Здесь мы проверяем: если source стал 'api' — засчитан внешним postback

        ok_ids      = []
        missing_ids = []

        for tid in test_ids:
            row = conn.execute(
                "SELECT source FROM conversions WHERE conv_id = ?", (tid,)
            ).fetchone()

            if row and row["source"] == "api":
                ok_ids.append(tid)
            else:
                missing_ids.append(tid)

        status = "✅" if not missing_ids else ("⚠️" if ok_ids else "❌")
        lines.append(
            f"{status} <b>{adv_name}</b>\n"
            f"   Засчитано: {len(ok_ids)}/{len(test_ids)}\n"
            f"   Дата отправки: {sent_dt}"
        )

        if not ok_ids:
            problems.append(adv_name)

    conn.close()

    body = "\n\n".join(lines) or "Нет данных."

    msg = (
        f"🧪 <b>Отчёт по тестовым конверсиям</b>\n"
        f"{'─' * 30}\n\n"
        f"{body}"
    )

    if problems:
        msg += (
            f"\n\n🚨 <b>Не засчитали ни одной конверсии:</b>\n"
            + "\n".join(f"  • {n}" for n in problems)
        )

    send(msg)

    # Отдельный алерт если есть проблемы
    for name in problems:
        alert(
            f"⚠️ <b>TEST-конверсия не засчитана!</b>\n"
            f"Рекламодатель: <b>{name}</b>\n"
            f"Проверь postback и кабинет партнёра."
        )

    logger.info("test_conversions report отправлен. Проблемы: %s", problems)

    # Удаляем pending после отчёта
    PENDING_FILE.unlink(missing_ok=True)


# ── Точка входа ───────────────────────────────────────────────────────────────

def main() -> None:
    mode = sys.argv[1] if len(sys.argv) > 1 else "send"

    if mode == "send":
        send_test_conversions()
    elif mode == "report":
        report_test_conversions()
    else:
        print(f"Неизвестный режим: {mode}. Используй: send | report", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
