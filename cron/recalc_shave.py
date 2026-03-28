#!/usr/bin/env python3
"""
Пересчёт shave_cache за последние 7 дней. Запуск из cron (раз в час).
"""
from __future__ import annotations

import logging
import sqlite3
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DB_PATH = ROOT / "data" / "clicks.db"

logging.basicConfig(level=logging.INFO, format="%(message)s")
log = logging.getLogger("recalc_shave")


def run_recalc() -> tuple[int, float | None]:
    if not DB_PATH.exists():
        log.warning("Нет БД %s", DB_PATH)
        return 0, None

    since = int(time.time()) - 7 * 86400
    conn = sqlite3.connect(str(DB_PATH), timeout=60)
    try:
        cur = conn.cursor()
        cur.execute(
            """
            SELECT advertiser_id, COUNT(*) AS clicks
            FROM clicks
            WHERE ts >= ? AND status = 'sent'
            GROUP BY advertiser_id
            """,
            (since,),
        )
        clicks_by_adv = {str(r[0]): int(r[1]) for r in cur.fetchall() if r[0]}

        cur.execute(
            """
            SELECT advertiser_id, SUM(count) AS convs
            FROM conversions
            WHERE created_at >= ?
            GROUP BY advertiser_id
            """,
            (since,),
        )
        conv_by_adv = {str(r[0]): int(r[1] or 0) for r in cur.fetchall() if r[0]}

        crs_positive: list[float] = []
        cr_by_adv: dict[str, float] = {}
        for aid, cl in clicks_by_adv.items():
            if cl <= 0:
                continue
            conv = conv_by_adv.get(aid, 0)
            cr = conv / cl
            cr_by_adv[aid] = cr
            if cr > 0:
                crs_positive.append(cr)

        if not crs_positive:
            median = None
        else:
            crs_positive.sort()
            n = len(crs_positive)
            mid = n // 2
            median = (crs_positive[mid - 1] + crs_positive[mid]) / 2 if n % 2 == 0 else crs_positive[mid]

        now = int(time.time())
        n_adv = 0
        if median and median > 0:
            for aid, cr_adv in cr_by_adv.items():
                shave = max(0.0, (median - cr_adv) / median)
                shave = min(1.0, shave)
                cur.execute(
                    """
                    INSERT OR REPLACE INTO shave_cache
                    (advertiser_id, shave_coef, cr, median_cr, calculated_at)
                    VALUES (?, ?, ?, ?, ?)
                    """,
                    (aid, shave, cr_adv, median, now),
                )
                n_adv += 1
        else:
            for aid in clicks_by_adv:
                cur.execute(
                    """
                    INSERT OR REPLACE INTO shave_cache
                    (advertiser_id, shave_coef, cr, median_cr, calculated_at)
                    VALUES (?, 0.0, ?, ?, ?)
                    """,
                    (aid, cr_by_adv.get(aid, 0.0), 0.0, now),
                )
                n_adv += 1

        cur.execute(
            "DELETE FROM click_fingerprints WHERE created_at < strftime('%s', 'now') - 3600"
        )
        conn.commit()
        pct = (median * 100) if median else 0.0
        log.info("Shave cache updated: %d advertisers, median CR=%.2f%%", n_adv, pct)
        return n_adv, median
    finally:
        conn.close()


if __name__ == "__main__":
    try:
        n, med = run_recalc()
        sys.exit(0 if n >= 0 else 1)
    except Exception as e:
        log.exception("%s", e)
        sys.exit(1)
