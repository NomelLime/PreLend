"""
internal_api/auth.py — API Key авторизация для PreLend Internal API.

Ключ передаётся в заголовке X-API-Key.
Если PL_INTERNAL_API_KEY не задан — dev-режим, аутентификация пропускается,
но в лог пишется предупреждение (один раз при первом запросе).
"""
from __future__ import annotations

import logging

from fastapi import HTTPException, Security, status
from fastapi.security import APIKeyHeader

from . import config as cfg

_header_scheme  = APIKeyHeader(name="X-API-Key", auto_error=False)
_logger         = logging.getLogger(__name__)
_dev_mode_warned = False


async def require_api_key(key: str = Security(_header_scheme)) -> str:
    """FastAPI dependency: проверяет API Key в заголовке X-API-Key."""
    global _dev_mode_warned

    if not cfg.API_KEY:
        if not _dev_mode_warned:
            _logger.warning(
                "⚠️  PL_INTERNAL_API_KEY не задан — API работает БЕЗ авторизации! "
                "Задайте ключ: export PL_INTERNAL_API_KEY=$(python3 -c "
                "'import secrets; print(secrets.token_hex(32))')"
            )
            _dev_mode_warned = True
        return "dev"

    if not key or key != cfg.API_KEY:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Invalid or missing API key",
        )
    return key
