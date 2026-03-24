"""
internal_api/routes/configs.py

Эндпоинты:
    GET  /config/{name}  — чтение JSON-конфига
    PUT  /config/{name}  — атомичная перезапись + git commit

Доступные имена: settings | advertisers | geo_data | splits
"""
from __future__ import annotations

import json
import logging
import os
import subprocess
import tempfile
from pathlib import Path
from typing import Any, Dict, List, Optional, Union

from fastapi import APIRouter, Depends, HTTPException, Request

from .. import config as cfg
from ..auth import require_api_key

router = APIRouter()
logger = logging.getLogger(__name__)

_CONFIG_MAP: Dict[str, Path] = {
    "settings":    cfg.SETTINGS_JSON,
    "advertisers": cfg.ADVERTISERS_JSON,
    "geo_data":    cfg.GEO_DATA_JSON,
    "splits":      cfg.SPLITS_JSON,
}

# Допустимые top-level ключи в settings.json (whitelist — protection-in-depth)
_SETTINGS_ALLOWED_KEYS = {
    "default_offer_url", "cloak_url", "db_path", "log_path",
    "alerts", "test_conversion_prefix", "cloudflare", "scoring",
    "redirect_delay_ms", "timezone", "ollama", "postback_token",
    "cloak_template",
}

# Максимальный размер тела запроса (байт) — защита от мусорных записей
_MAX_BODY_BYTES = 1_000_000


def _list_templates() -> Dict[str, List[str]]:
    """Возвращает доступные шаблоны из templates/offers и templates/cloaked."""
    base = cfg.ROOT / "templates"
    result: Dict[str, List[str]] = {"offers": [], "cloaked": []}
    for key in ("offers", "cloaked"):
        folder = base / key
        if not folder.exists():
            continue
        result[key] = sorted([p.stem for p in folder.glob("*.php") if p.is_file()])
    return result


def _validate_body(name: str, body: Any) -> Optional[str]:
    """
    Валидирует тело PUT /config/{name}.
    Возвращает строку с описанием ошибки или None если всё ок.
    """
    # Размерный лимит
    try:
        body_size = len(json.dumps(body).encode("utf-8"))
    except Exception:
        return "Тело запроса не сериализуется в JSON"
    if body_size > _MAX_BODY_BYTES:
        return f"Тело запроса слишком большое ({body_size} байт > {_MAX_BODY_BYTES})"

    if name == "settings":
        if not isinstance(body, dict):
            return "settings должен быть объектом (dict)"
        unknown = set(body.keys()) - _SETTINGS_ALLOWED_KEYS
        if unknown:
            return f"Недопустимые ключи в settings: {sorted(unknown)}"

    elif name == "advertisers":
        if not isinstance(body, list):
            return "advertisers должен быть массивом (list)"
        for i, item in enumerate(body):
            if not isinstance(item, dict):
                return f"advertisers[{i}] должен быть объектом"
            missing = {"id", "name", "status"} - set(item.keys())
            if missing:
                return f"advertisers[{i}] отсутствуют обязательные ключи: {missing}"

    elif name == "splits":
        if not isinstance(body, list):
            return "splits должен быть массивом (list)"

    elif name == "geo_data":
        if not isinstance(body, dict):
            return "geo_data должен быть объектом (dict)"

    return None


# ── Helpers ────────────────────────────────────────────────────────────────────

def _read_json(path: Path) -> Union[Dict, List]:
    if not path.exists():
        return {} if "settings" in path.name or "geo" in path.name else []
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        logger.error("[configs] Ошибка чтения %s: %s", path, exc)
        raise HTTPException(500, f"Ошибка чтения конфига: {exc}")


def _atomic_write_json(path: Path, data: Any) -> None:
    """Атомарная запись через временный файл (write → rename)."""
    path.parent.mkdir(parents=True, exist_ok=True)
    text = json.dumps(data, ensure_ascii=False, indent=2)
    fd, tmp = tempfile.mkstemp(dir=str(path.parent), suffix=".tmp")
    try:
        os.write(fd, text.encode("utf-8"))
        os.close(fd)
        os.replace(tmp, str(path))
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


def _git_commit(file_path: Path, message: str) -> None:
    """Git commit изменённого файла на VPS (если GIT_AUTOCOMMIT включён)."""
    if not cfg.GIT_AUTOCOMMIT:
        return
    try:
        rel = str(file_path.relative_to(cfg.ROOT))
        subprocess.run(
            ["git", "add", rel],
            cwd=str(cfg.ROOT), capture_output=True, timeout=10, check=False,
        )
        result = subprocess.run(
            ["git", "commit", "-m", message],
            cwd=str(cfg.ROOT), capture_output=True, timeout=10, check=False,
        )
        stdout = result.stdout.decode("utf-8", errors="ignore")
        if result.returncode == 0 or "nothing to commit" in stdout:
            logger.info("[configs] git commit: %s", message[:80])
        else:
            logger.warning("[configs] git commit failed: %s", result.stderr.decode()[:200])
    except Exception as exc:
        logger.warning("[configs] git commit exception: %s", exc)


async def _extract_request_body(request: Request) -> Any:
    """
    Унифицированный парсер тела PUT /config/{name}.

    Поддерживаем:
      - application/json: тело = JSON (dict/list)
      - form/multipart: поле body (JSON-строка или уже dict/list)
    Это нужно для совместимости с legacy-клиентами.
    """
    ctype = (request.headers.get("content-type") or "").lower()

    # Предпочтительный формат — чистый JSON.
    if "application/json" in ctype or ctype == "":
        try:
            return await request.json()
        except Exception as exc:
            raise HTTPException(400, f"Невалидный JSON в теле запроса: {exc}")

    # Legacy-формат: body в form-data/urlencoded.
    if "multipart/form-data" in ctype or "application/x-www-form-urlencoded" in ctype:
        try:
            form = await request.form()
        except Exception as exc:
            raise HTTPException(400, f"Не удалось прочитать form-data: {exc}")
        if "body" not in form:
            raise HTTPException(422, "Поле 'body' обязательно для form-data запроса")
        raw = form.get("body")
        if isinstance(raw, (dict, list)):
            return raw
        if hasattr(raw, "read"):
            # UploadFile: читаем содержимое как текст JSON
            try:
                raw_bytes = await raw.read()
                raw = raw_bytes.decode("utf-8")
            except Exception as exc:
                raise HTTPException(400, f"Не удалось прочитать upload body: {exc}")
        if not isinstance(raw, str):
            raise HTTPException(400, "Поле 'body' должно быть JSON-строкой")
        try:
            return json.loads(raw)
        except Exception as exc:
            raise HTTPException(400, f"Поле 'body' не содержит валидный JSON: {exc}")

    raise HTTPException(415, f"Неподдерживаемый Content-Type: {ctype}")


# ── Эндпоинты ─────────────────────────────────────────────────────────────────

@router.get("/config/{name}")
def read_config(
    name: str,
    _key: str = Depends(require_api_key),
):
    """Возвращает JSON-конфиг PreLend по имени."""
    if name not in _CONFIG_MAP:
        raise HTTPException(
            404,
            f"Конфиг '{name}' не найден. Доступные: {list(_CONFIG_MAP)}",
        )
    return _read_json(_CONFIG_MAP[name])


@router.get("/templates")
def list_templates(
    _key: str = Depends(require_api_key),
):
    """Список доступных шаблонов PreLend для UI."""
    return _list_templates()


@router.put("/config/{name}")
async def write_config(
    name: str,
    request: Request,
    source: str = "remote",
    _key: str = Depends(require_api_key),
):
    """
    Атомарно перезаписывает JSON-конфиг PreLend и делает git commit на VPS.

    source — метка источника изменений (например, 'orchestrator/plan_12' или 'contenthub:admin').
    """
    if name not in _CONFIG_MAP:
        raise HTTPException(
            404,
            f"Конфиг '{name}' не найден. Доступные: {list(_CONFIG_MAP)}",
        )

    body = await _extract_request_body(request)

    # Валидация содержимого (whitelist, типы, размер)
    err = _validate_body(name, body)
    if err:
        raise HTTPException(400, f"Невалидное тело запроса для '{name}': {err}")

    path = _CONFIG_MAP[name]
    try:
        _atomic_write_json(path, body)
    except Exception as exc:
        logger.error("[configs] Ошибка записи %s: %s", name, exc)
        raise HTTPException(500, f"Ошибка записи конфига: {exc}")

    _git_commit(path, f"[{source}] update config/{name}")
    logger.info("[configs] %s обновлён (source=%s)", name, source)

    return {"success": True, "config": name, "source": source}
