"""
internal_api/main.py — PreLend Internal API.

Лёгкий FastAPI-сервис для доступа к данным PreLend с локальной машины.
Слушает ТОЛЬКО на 127.0.0.1:9090 (или через WireGuard).

Запуск:
    cd /var/www/prelend
    PL_INTERNAL_API_KEY=secret uvicorn internal_api.main:app --host 127.0.0.1 --port 9090

Через systemd (рекомендуется):
    systemctl start prelend-internal-api

Swagger UI (только в dev-режиме, когда PL_INTERNAL_API_KEY не задан):
    http://localhost:9090/docs
"""
from __future__ import annotations

import logging
import sqlite3
import time

from fastapi import FastAPI

from . import config as cfg
from .routes import agents, configs, metrics

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [internal_api] %(levelname)-8s %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)

app = FastAPI(
    title="PreLend Internal API",
    description="Внутренний API для Orchestrator и ContentHub. Не публикуется в интернет.",
    version="1.0.0",
    # Swagger только в dev-режиме (когда ключ не задан)
    docs_url="/docs" if not cfg.API_KEY else None,
    redoc_url=None,
)

app.include_router(metrics.router, tags=["metrics"])
app.include_router(configs.router, tags=["configs"])
app.include_router(agents.router,  tags=["agents"])


def _connect_clicks():
    """Открывает clicks.db только на чтение. Возвращает Connection или None."""
    if not cfg.CLICKS_DB.exists():
        return None
    try:
        conn = sqlite3.connect(str(cfg.CLICKS_DB), timeout=3)
        conn.row_factory = sqlite3.Row
        return conn
    except Exception:
        return None


@app.get("/health", tags=["health"])
def health():
    """Расширенная проверка: доступность API, БД, размер БД, последний клик."""
    import os
    db_path = cfg.CLICKS_DB

    result: dict = {
        "status":    "ok",
        "db_exists": db_path.exists(),
        "host":      cfg.HOST,
        "port":      cfg.PORT,
        "auth":      "enabled" if cfg.API_KEY else "disabled (dev mode)",
    }

    if db_path.exists():
        try:
            result["db_size_mb"] = round(os.path.getsize(db_path) / 1024 / 1024, 2)
        except OSError:
            pass

        conn = _connect_clicks()
        if conn:
            try:
                row = conn.execute("SELECT MAX(ts) AS last_ts FROM clicks").fetchone()
                if row and row["last_ts"]:
                    last_ago = int(time.time()) - int(row["last_ts"])
                    result["last_click_ago_sec"] = last_ago
                    result["traffic_alive"]      = last_ago < 3600  # клик был в последний час

                pending = conn.execute(
                    "SELECT COUNT(*) AS cnt FROM clicks WHERE status = 'sent' AND ts > ?",
                    (int(time.time()) - 86400,),
                ).fetchone()
                if pending:
                    result["pending_clicks_24h"] = pending["cnt"]
            except Exception as exc:
                result["db_error"] = str(exc)
            finally:
                conn.close()

    return result
