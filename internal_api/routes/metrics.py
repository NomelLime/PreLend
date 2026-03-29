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
from urllib.parse import quote as _url_quote

from fastapi import APIRouter, Depends, HTTPException, Query

from .. import config as cfg
from ..auth import require_api_key

router = APIRouter()
logger = logging.getLogger(__name__)


def _run_recalc_shave_impl() -> tuple[int, float | None]:
    """Импорт логики из cron/recalc_shave.py без дублирования кода."""
    import importlib.util

    path = cfg.ROOT / "cron" / "recalc_shave.py"
    spec = importlib.util.spec_from_file_location("prelend_recalc_shave", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("recalc_shave.py не найден")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod.run_recalc()


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
        total_clicks, conversions, cr, bot_pct, top_geo, geo_breakdown,
        shave_suspects, analyst_verdicts, agent_statuses
    """
    result: Dict[str, Any] = {
        "period_hours":     period_hours,
        "total_clicks":     0,
        "conversions":      0,
        "cr":               None,
        "bot_pct":          None,
        "top_geo":          None,
        "geo_breakdown":    [],
        "shave_suspects":   [],
        "analyst_verdicts": {},
        "agent_statuses":   {},
        "by_advertiser":    [],
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

            # Разбивка по ГЕО: клики (без bot/cloaked) и конверсии по статусу клика
            geo_rows = conn.execute(
                """
                SELECT
                    COALESCE(NULLIF(TRIM(geo), ''), '—') AS geo,
                    COUNT(*) AS clicks,
                    SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) AS conversions
                FROM clicks
                WHERE ts >= ? AND is_test = 0 AND status NOT IN ('bot', 'cloaked')
                GROUP BY 1
                ORDER BY clicks DESC
                """,
                (since_ts,),
            ).fetchall()

            breakdown = []
            for gr in geo_rows:
                c = int(gr["clicks"] or 0)
                conv = int(gr["conversions"] or 0)
                breakdown.append(
                    {
                        "geo": gr["geo"],
                        "clicks": c,
                        "conversions": conv,
                        "cr": round(conv / c, 6) if c > 0 else 0.0,
                    }
                )
            result["geo_breakdown"] = breakdown

            # Разбивка по рекламодателям (для ContentHub /api/advertisers/compare)
            by_adv: Dict[str, Dict[str, Any]] = {}
            adv_click_rows = conn.execute(
                """
                SELECT
                    COALESCE(NULLIF(TRIM(advertiser_id), ''), '—') AS aid,
                    COUNT(*) AS clicks,
                    SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) AS conv_on_click
                FROM clicks
                WHERE ts >= ? AND is_test = 0 AND status NOT IN ('bot', 'cloaked')
                GROUP BY 1
                """,
                (since_ts,),
            ).fetchall()
            for gr in adv_click_rows:
                aid = str(gr["aid"] or "—")
                c = int(gr["clicks"] or 0)
                by_adv[aid] = {
                    "advertiser_id": aid,
                    "clicks": c,
                    "conversions": 0,
                    "cr": 0.0,
                }

            conv_adv_rows = conn.execute(
                """
                SELECT advertiser_id, SUM(count) AS cnt
                FROM conversions
                WHERE created_at >= ?
                GROUP BY advertiser_id
                """,
                (since_ts,),
            ).fetchall()
            for gr in conv_adv_rows:
                aid_raw = gr["advertiser_id"]
                aid = str(aid_raw).strip() if aid_raw is not None else "—"
                if not aid:
                    aid = "—"
                cnt = int(gr["cnt"] or 0)
                if aid not in by_adv:
                    by_adv[aid] = {
                        "advertiser_id": aid,
                        "clicks": 0,
                        "conversions": 0,
                        "cr": 0.0,
                    }
                by_adv[aid]["conversions"] = cnt

            by_list = []
            for row in by_adv.values():
                c = int(row["clicks"])
                conv = int(row["conversions"])
                row["cr"] = round(conv / c, 6) if c > 0 else 0.0
                by_list.append(row)
            by_list.sort(key=lambda x: (-x["clicks"], x["advertiser_id"]))
            result["by_advertiser"] = by_list

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


@router.post("/metrics/recalc-shave")
async def recalc_shave(_key: str = Depends(require_api_key)):
    """Пересчёт shave_cache (cron или вручную)."""
    try:
        n, median = _run_recalc_shave_impl()
        pct = round(median * 100, 4) if median else None
        return {"recalculated": n, "median_cr": pct}
    except Exception as exc:
        logger.exception("[metrics/recalc-shave] %s", exc)
        raise HTTPException(500, str(exc)) from exc


@router.post("/register_video")
async def register_video(body: dict, _key: str = Depends(require_api_key)):
    """Регистрирует видео SP и возвращает трекинговый URL с UTM."""
    video_stem = str(body.get("video_stem") or "").strip()
    platform = str(body.get("platform") or "").strip()
    video_url = str(body.get("video_url") or "").strip()
    account = str(body.get("account") or "").strip()
    if not video_stem or not platform:
        raise HTTPException(400, "video_stem and platform required")

    utm_content = f"sp_{video_stem}_{platform}"
    settings = _safe_read_json(cfg.SETTINGS_JSON) or {}
    base_url = str(settings.get("default_offer_url") or "https://pulsority.com").rstrip("/")
    # [FIX] URL-encode параметров: video_stem может содержать & или # → сломает URL
    tracking_url = (
        f"{base_url}?utm_source={_url_quote(platform, safe='')}"
        f"&utm_medium=shorts"
        f"&utm_content={_url_quote(utm_content, safe='')}"
    )

    conn = _connect_clicks()
    if not conn:
        raise HTTPException(503, "clicks.db недоступна")
    try:
        conn.execute("PRAGMA foreign_keys = ON")
        conn.execute(
            """
            INSERT OR REPLACE INTO video_links
            (video_stem, platform, video_url, account_name, utm_content, tracking_url, created_at)
            VALUES (?,?,?,?,?,?,?)
            """,
            (video_stem, platform, video_url or None, account or None, utm_content, tracking_url, int(time.time())),
        )
        conn.commit()
    except Exception as exc:
        logger.error("[register_video] %s", exc)
        raise HTTPException(500, str(exc)) from exc
    finally:
        conn.close()

    return {"tracking_url": tracking_url, "utm_content": utm_content}
