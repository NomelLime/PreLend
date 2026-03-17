"""
internal_api/routes/metrics.py

Эндпоинты:
    GET /metrics              — агрегированные метрики за period_hours
    GET /metrics/financial    — конверсии с payout (для FinancialObserver)
    GET /metrics/funnel       — клики по utm_content (для FunnelLinker)
"""
from __future__ import annotations

import json
import logging
import sqlite3
import time
from typing import Any, Dict, Optional

from fastapi import APIRouter, Depends, Query

from .. import config as cfg
from ..auth import require_api_key

router = APIRouter()
logger = logging.getLogger(__name__)


# ── Helpers ────────────────────────────────────────────────────────────────────

def _safe_read_json(path) -> Optional[Dict]:
    try:
        if path.exists():
            return json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        logger.warning("[metrics] Не удалось прочитать %s: %s", path, exc)
    return None


def _connect_clicks(timeout: float = 5.0) -> Optional[sqlite3.Connection]:
    if not cfg.CLICKS_DB.exists():
        return None
    try:
        conn = sqlite3.connect(str(cfg.CLICKS_DB), timeout=timeout)
        conn.row_factory = sqlite3.Row
        return conn
    except sqlite3.Error as exc:
        logger.error("[metrics] Не удалось открыть clicks.db: %s", exc)
        return None


# ── Основные метрики ───────────────────────────────────────────────────────────

@router.get("/metrics")
def get_metrics(
    period_hours: int = Query(24, ge=1, le=168),
    _key: str = Depends(require_api_key),
) -> Dict[str, Any]:
    """
    Агрегированные метрики PreLend за period_hours.

    Возвращает:
        total_clicks, conversions, cr, bot_pct, top_geo,
        shave_suspects, analyst_verdicts, agent_statuses
    """
    result: Dict[str, Any] = {
        "period_hours":     period_hours,
        "total_clicks":     0,
        "conversions":      0,
        "cr":               None,
        "bot_pct":          None,
        "top_geo":          None,
        "shave_suspects":   [],
        "analyst_verdicts": {},
        "agent_statuses":   {},
    }

    # ── clicks.db ──────────────────────────────────────────────────────────────
    conn = _connect_clicks()
    if conn:
        try:
            since_ts = int(time.time()) - period_hours * 3600

            row = conn.execute("""
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'bot'     THEN 1 ELSE 0 END) AS bots,
                    SUM(CASE WHEN status = 'cloaked' THEN 1 ELSE 0 END) AS cloaked
                FROM clicks
                WHERE ts >= ? AND is_test = 0
            """, (since_ts,)).fetchone()

            if row and row["total"] > 0:
                result["total_clicks"] = row["total"]
                result["bot_pct"] = (row["bots"] or 0) / row["total"] * 100

            conv_row = conn.execute("""
                SELECT COALESCE(SUM(count), 0) AS total_convs
                FROM conversions WHERE created_at >= ?
            """, (since_ts,)).fetchone()

            if conv_row:
                result["conversions"] = int(conv_row["total_convs"])
                if result["total_clicks"] > 0:
                    result["cr"] = result["conversions"] / result["total_clicks"]

            geo_row = conn.execute("""
                SELECT geo, COUNT(*) AS cnt FROM clicks
                WHERE ts >= ? AND is_test = 0 AND status NOT IN ('bot', 'cloaked')
                GROUP BY geo ORDER BY cnt DESC LIMIT 1
            """, (since_ts,)).fetchone()

            if geo_row:
                result["top_geo"] = geo_row["geo"]

        except Exception as exc:
            logger.error("[metrics] Ошибка чтения clicks.db: %s", exc)
        finally:
            conn.close()

    # ── shave_report.json ──────────────────────────────────────────────────────
    shave = _safe_read_json(cfg.SHAVE_REPORT)
    if shave:
        report = shave.get("report", {})
        result["shave_suspects"] = [
            {"id": adv_id, **data}
            for adv_id, data in report.items()
            if data.get("suspected_shave") or data.get("verdict") == "shave_suspected"
        ]

    # ── agent_memory.json ──────────────────────────────────────────────────────
    memory = _safe_read_json(cfg.AGENT_MEMORY)
    if memory:
        result["agent_statuses"] = memory.get("agent_statuses", {})
        kv = memory.get("kv", {})
        # Вердикты аналитика
        verdicts_data = kv.get("analyst_last_verdicts", {})
        result["analyst_verdicts"] = verdicts_data.get("verdicts", {})

    return result


# ── Финансовые метрики ─────────────────────────────────────────────────────────

@router.get("/metrics/financial")
def get_financial_metrics(
    period_hours: int = Query(24, ge=1, le=720),
    _key: str = Depends(require_api_key),
) -> Dict[str, Any]:
    """
    Конверсии с payout из clicks.db для FinancialObserver.
    Возвращает raw-строки конверсий за period_hours.
    """
    result: Dict[str, Any] = {"conversions": [], "period_hours": period_hours}

    conn = _connect_clicks()
    if not conn:
        return result

    try:
        since_ts = int(time.time()) - period_hours * 3600

        rows = conn.execute("""
            SELECT id, date, advertiser_id, count, source, notes, created_at
            FROM conversions
            WHERE created_at >= ?
            ORDER BY created_at DESC
        """, (since_ts,)).fetchall()

        result["conversions"] = [dict(r) for r in rows]
    except Exception as exc:
        logger.error("[metrics/financial] Ошибка: %s", exc)
    finally:
        conn.close()

    return result


# ── Воронка ────────────────────────────────────────────────────────────────────

@router.get("/metrics/funnel")
def get_funnel_data(
    period_hours: int = Query(168, ge=1, le=720),
    _key: str = Depends(require_api_key),
) -> Dict[str, Any]:
    """
    Клики сгруппированные по utm_content для FunnelLinker (SP→PL воронка).
    utm_content = sp_{stem} — связывает видео SP с кликами PreLend.
    """
    result: Dict[str, Any] = {"clicks": [], "period_hours": period_hours}

    conn = _connect_clicks()
    if not conn:
        return result

    try:
        since_ts = int(time.time()) - period_hours * 3600

        rows = conn.execute("""
            SELECT utm_content, geo, status, COUNT(*) as cnt
            FROM clicks
            WHERE ts >= ? AND is_test = 0 AND utm_content IS NOT NULL
            GROUP BY utm_content, geo, status
        """, (since_ts,)).fetchall()

        result["clicks"] = [dict(r) for r in rows]

        # Конверсии с payout по sub_id (для revenue в воронке)
        conv_rows = conn.execute("""
            SELECT notes, count
            FROM conversions
            WHERE created_at >= ? AND notes IS NOT NULL
        """, (since_ts,)).fetchall()

        result["conversion_notes"] = [dict(r) for r in conv_rows]

    except Exception as exc:
        logger.error("[metrics/funnel] Ошибка: %s", exc)
    finally:
        conn.close()

    return result
