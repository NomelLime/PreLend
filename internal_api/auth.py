"""
internal_api/auth.py — API Key авторизация для PreLend Internal API.

Ключ передаётся в заголовке X-API-Key.
Если PL_INTERNAL_API_KEY не задан — dev-режим, аутентификация пропускается.
"""
from __future__ import annotations

from fastapi import HTTPException, Security, status
from fastapi.security import APIKeyHeader

from . import config as cfg

_header_scheme = APIKeyHeader(name="X-API-Key", auto_error=False)


async def require_api_key(key: str = Security(_header_scheme)) -> str:
    """FastAPI dependency: проверяет API Key в заголовке X-API-Key."""
    if not cfg.API_KEY:
        # Ключ не задан → dev-режим, пропускаем авторизацию
        return "dev"
    if key != cfg.API_KEY:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Invalid or missing API key",
        )
    return key
