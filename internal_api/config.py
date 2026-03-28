"""
internal_api/config.py — Конфиг PreLend Internal API.

Все пути берутся относительно корня PreLend (ROOT).
Всё настраивается через переменные окружения — ничего не захардкожено.
"""
from __future__ import annotations

import os
from pathlib import Path

# Корень PreLend (на уровень выше internal_api/)
ROOT = Path(__file__).resolve().parent.parent

# GitHub/.secrets.env → PreLend/.env (как у остальных сервисов монорепы)
try:
    from dotenv import load_dotenv

    load_dotenv(ROOT.parent / ".secrets.env", override=False)
    load_dotenv(ROOT / ".env", override=True)
except ImportError:
    pass

# ── Пути к данным PreLend ──────────────────────────────────────────────────────
CLICKS_DB        = ROOT / "data"   / "clicks.db"
SETTINGS_JSON    = ROOT / "config" / "settings.json"
ADVERTISERS_JSON = ROOT / "config" / "advertisers.json"
GEO_DATA_JSON    = ROOT / "config" / "geo_data.json"
SPLITS_JSON      = ROOT / "config" / "splits.json"
AGENT_MEMORY     = ROOT / "data"   / "agent_memory.json"
SHAVE_REPORT     = ROOT / "data"   / "shave_report.json"

# ── Безопасность ───────────────────────────────────────────────────────────────
# ОБЯЗАТЕЛЬНО задать в .env на VPS. Пустая строка = dev-режим (без аутентификации).
# Сгенерировать: python3 -c "import secrets; print(secrets.token_hex(32))"
import logging as _logging

API_KEY = os.getenv("PL_INTERNAL_API_KEY", "")

if not API_KEY:
    _logging.getLogger(__name__).warning(
        "PL_INTERNAL_API_KEY не задан. Internal API запущен в dev-режиме (без авторизации). "
        "Задайте ключ в .env перед деплоем на VPS."
    )

# Слушать только на localhost — API никогда не должен быть виден из интернета.
# Для WireGuard: поменяй на 10.0.0.1 (внутренний IP туннеля).
HOST = os.getenv("PL_INTERNAL_HOST", "127.0.0.1")
PORT = int(os.getenv("PL_INTERNAL_PORT", "9090"))

# ── Git autocommit ─────────────────────────────────────────────────────────────
# При PUT /config/{name} делать git commit на VPS (трекинг изменений).
GIT_AUTOCOMMIT = os.getenv("PL_GIT_AUTOCOMMIT", "true").lower() == "true"
