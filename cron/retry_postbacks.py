#!/usr/bin/env python3
"""
Обработка data/postback_retry.jsonl — повторная вставка конверсий (логика как ConversionLogger::logApi).
"""
from __future__ import annotations

import json
import logging
import os
import sqlite3
import sys
import time
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
RETRY_FILE = ROOT / "data" / "postback_retry.jsonl"
DB_PATH = ROOT / "data" / "clicks.db"

logging.basicConfig(level=logging.INFO, format="%(message)s")
log = logging.getLogger("retry_postbacks")


def _new_conv_id() -> str:
    return str(uuid.uuid4())


def try_insert(conn: sqlite3.Connection, row: dict) -> str:
    """'ok' | 'duplicate' | 'retry' | 'skip'."""
    click_id = str(row.get("click_id") or "")
    adv_id = str(row.get("adv_id") or "")
    date = str(row.get("date") or "")
    count = max(1, int(row.get("count") or 1))
    notes = str(row.get("notes") or "")
    if not click_id or not adv_id:
        return "skip"

    cur = conn.cursor()
    cur.execute("SELECT status FROM clicks WHERE click_id = ? LIMIT 1", (click_id,))
    r = cur.fetchone()
    if not r:
        return "retry"
    if r[0] == "converted":
        return "duplicate"

    conv_id = _new_conv_id()
    try:
        cur.execute(
            """
            INSERT INTO conversions (conv_id, date, advertiser_id, count, source, notes, created_at)
            VALUES (?, ?, ?, ?, 'api', ?, ?)
            """,
            (conv_id, date, adv_id, count, notes, int(time.time())),
        )
        cur.execute("UPDATE clicks SET status = 'converted' WHERE click_id = ?", (click_id,))
        conn.commit()
        return "ok"
    except sqlite3.IntegrityError:
        conn.rollback()
        return "duplicate"
    except Exception:
        conn.rollback()
        return "retry"


def main() -> int:
    if not RETRY_FILE.is_file():
        return 0
    raw_lines = [ln.strip() for ln in RETRY_FILE.read_text(encoding="utf-8", errors="replace").splitlines() if ln.strip()]
    if not raw_lines:
        return 0
    if not DB_PATH.exists():
        log.error("Нет БД %s", DB_PATH)
        return 1

    conn = sqlite3.connect(str(DB_PATH), timeout=30)
    kept: list[str] = []
    now = int(time.time())
    tg_token = os.getenv("ORC_TG_TOKEN") or os.getenv("PL_TG_ALERT_TOKEN", "")
    tg_chat = os.getenv("ORC_TG_CHAT_ID") or os.getenv("PL_TG_ALERT_CHAT_ID", "")

    try:
        for line in raw_lines:
            try:
                row = json.loads(line)
            except json.JSONDecodeError:
                continue
            failed_at = int(row.get("failed_at") or 0)
            if now - failed_at > 86400:
                log.warning("Отброс постбэка старше 24ч: %s", row.get("click_id"))
                continue
            outcome = try_insert(conn, row)
            if outcome in ("ok", "duplicate"):
                continue
            kept.append(line)

        # [FIX] Атомарная перезапись: tmpfile → os.replace() предотвращает потерю данных при крэше
        tmp_file = RETRY_FILE.with_suffix(".jsonl.tmp")
        tmp_file.write_text("\n".join(kept) + ("\n" if kept else ""), encoding="utf-8")
        os.replace(tmp_file, RETRY_FILE)

        if len(kept) > 10 and tg_token and tg_chat:
            try:
                import urllib.request

                msg = f"postback_retry queue: {len(kept)} записей"
                url = f"https://api.telegram.org/bot{tg_token}/sendMessage"
                body = json.dumps({"chat_id": tg_chat, "text": msg}).encode()
                req = urllib.request.Request(url, data=body, headers={"Content-Type": "application/json"})
                urllib.request.urlopen(req, timeout=5)
            except Exception as e:
                log.warning("Telegram alert: %s", e)
    finally:
        conn.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
