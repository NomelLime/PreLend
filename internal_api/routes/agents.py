"""
internal_api/routes/agents.py

Эндпоинты:
    GET  /agents              — статусы всех агентов PreLend
    POST /agents/{name}/stop  — записать stop_request в agent_memory
    POST /agents/{name}/start — записать start_request (снять stop_request)
"""
from __future__ import annotations

import json
import logging
import os
import tempfile
from typing import Dict, List

from fastapi import APIRouter, Depends, HTTPException

from .. import config as cfg
from ..auth import require_api_key

router = APIRouter()
logger = logging.getLogger(__name__)

PL_AGENTS = ["COMMANDER", "ANALYST", "MONITOR", "OFFER_ROTATOR"]


# ── Helpers ────────────────────────────────────────────────────────────────────

def _read_memory() -> Dict:
    if not cfg.AGENT_MEMORY.exists():
        return {}
    try:
        return json.loads(cfg.AGENT_MEMORY.read_text(encoding="utf-8"))
    except Exception as exc:
        logger.warning("[agents] Ошибка чтения agent_memory.json: %s", exc)
        return {}


def _write_memory(data: Dict) -> None:
    """Атомарная запись agent_memory.json."""
    cfg.AGENT_MEMORY.parent.mkdir(parents=True, exist_ok=True)
    text = json.dumps(data, ensure_ascii=False, indent=2)
    fd, tmp = tempfile.mkstemp(dir=str(cfg.AGENT_MEMORY.parent), suffix=".tmp")
    try:
        os.write(fd, text.encode("utf-8"))
        os.close(fd)
        os.replace(tmp, str(cfg.AGENT_MEMORY))
    except Exception:
        try:
            os.close(fd)
        except OSError:
            pass
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise


# ── Эндпоинты ─────────────────────────────────────────────────────────────────

@router.get("/agents")
def list_agents(_key: str = Depends(require_api_key)) -> List[Dict]:
    """Возвращает статусы всех известных агентов PreLend."""
    memory   = _read_memory()
    statuses = memory.get("agent_statuses", {})

    return [
        {
            "name":       agent,
            "project":    "PreLend",
            "status":     statuses.get(agent, {}).get("status", "UNKNOWN"),
            "updated_at": statuses.get(agent, {}).get("updated_at"),
            "error":      statuses.get(agent, {}).get("last_error"),
        }
        for agent in PL_AGENTS
    ]


@router.post("/agents/{name}/{action}")
def control_agent(
    name: str,
    action: str,
    _key: str = Depends(require_api_key),
) -> Dict:
    """
    Отправляет управляющий сигнал агенту через agent_memory.json.

    action: stop | start
    """
    name_upper = name.upper()

    if name_upper not in PL_AGENTS:
        raise HTTPException(
            404,
            f"Агент '{name}' не найден. Доступные: {PL_AGENTS}",
        )
    if action not in ("stop", "start"):
        raise HTTPException(
            400,
            f"Действие '{action}' не поддерживается. Доступные: stop, start",
        )

    memory = _read_memory()
    ctrl   = memory.setdefault("kv", {})

    if action == "stop":
        ctrl[f"stop_request.{name_upper}"]  = True
        ctrl.pop(f"start_request.{name_upper}", None)
    else:  # start
        ctrl[f"start_request.{name_upper}"] = True
        ctrl.pop(f"stop_request.{name_upper}", None)

    _write_memory(memory)
    logger.info("[agents] %s_request для агента %s", action, name_upper)

    return {"success": True, "agent": name_upper, "action": action}
