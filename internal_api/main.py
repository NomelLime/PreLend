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


@app.get("/health", tags=["health"])
def health():
    """Проверка доступности API и БД."""
    return {
        "status":    "ok",
        "db_exists": cfg.CLICKS_DB.exists(),
        "host":      cfg.HOST,
        "port":      cfg.PORT,
        "auth":      "enabled" if cfg.API_KEY else "disabled (dev mode)",
    }
