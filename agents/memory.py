"""
agents/memory.py — Общее состояние агентов PreLend.

Переиспользует паттерн AgentMemory из ShortsProject:
  - потокобезопасный KV-стор
  - статусы агентов
  - лог событий (последние 500)
  - атомичная запись на диск (data/agent_memory.json)

Глобальный синглтон get_memory() — все агенты работают с одним объектом.
"""
from __future__ import annotations

import json
import logging
import os
import tempfile
import threading
from collections import deque
from datetime import datetime
from pathlib import Path
from typing import Any, Deque, Dict, List, Optional

logger = logging.getLogger(__name__)

_MEMORY_FILE = Path(__file__).resolve().parent.parent / "data" / "agent_memory.json"
_MAX_EVENTS  = 500


class AgentMemory:
    """
    Потокобезопасное in-memory хранилище с персистентностью на диск.

    Использование:
        memory = get_memory()
        memory.set("last_digest_ts", 1234567890)
        memory.log_event("COMMANDER", "command_received", {"cmd": "статус"})
    """

    def __init__(self, persist_path: Path = _MEMORY_FILE) -> None:
        self._persist_path = persist_path
        self._lock         = threading.RLock()
        self._kv:     Dict[str, Any]  = {}
        self._agents: Dict[str, str]  = {}
        self._events: Deque[Dict]     = deque(maxlen=_MAX_EVENTS)
        self._load()

    # ── KV ────────────────────────────────────────────────────────────────────

    def get(self, key: str, default: Any = None) -> Any:
        with self._lock:
            return self._kv.get(key, default)

    def set(self, key: str, value: Any, persist: bool = True) -> None:
        with self._lock:
            self._kv[key] = value
            if persist:
                self._save()

    def delete(self, key: str) -> None:
        with self._lock:
            self._kv.pop(key, None)
            self._save()

    def get_all_kv(self) -> Dict[str, Any]:
        with self._lock:
            return dict(self._kv)

    # ── Статусы агентов ───────────────────────────────────────────────────────

    def register_agent(self, name: str) -> None:
        with self._lock:
            if name not in self._agents:
                self._agents[name] = "IDLE"
                self._save()

    def set_agent_status(self, name: str, status: str) -> None:
        with self._lock:
            self._agents[name] = status
            # Статусы транзиентны — не сохраняем на диск при каждом обновлении

    def set_agent_report(self, name: str, data: Dict) -> None:
        key = f"report_{name.lower()}"
        with self._lock:
            self._kv[key] = {
                "ts":   datetime.now().isoformat(timespec="seconds"),
                "data": data,
            }
            self._save()

    def get_agent_status(self, name: str) -> Optional[str]:
        with self._lock:
            return self._agents.get(name)

    def get_all_agent_statuses(self) -> Dict[str, str]:
        with self._lock:
            return dict(self._agents)

    # ── Лог событий ───────────────────────────────────────────────────────────

    def log_event(self, agent: str, event: str, data: Optional[Dict] = None) -> None:
        with self._lock:
            self._events.append({
                "ts":    datetime.now().isoformat(timespec="seconds"),
                "agent": agent,
                "event": event,
                "data":  data or {},
            })

    def get_events(self, agent: Optional[str] = None, last_n: int = 50) -> List[Dict]:
        with self._lock:
            events = list(self._events)
        if agent:
            events = [e for e in events if e["agent"] == agent]
        return events[-last_n:]

    # ── Сводка ────────────────────────────────────────────────────────────────

    def summary(self) -> Dict:
        with self._lock:
            return {
                "kv_keys":     list(self._kv.keys()),
                "agents":      dict(self._agents),
                "event_count": len(self._events),
                "last_events": list(self._events)[-10:],
            }

    # ── Персистентность ───────────────────────────────────────────────────────

    def _save(self) -> None:
        """Атомичная запись через временный файл — защита от повреждения при сбое."""
        try:
            self._persist_path.parent.mkdir(parents=True, exist_ok=True)
            data = {
                "kv":       self._kv,
                "agents":   self._agents,
                "saved_at": datetime.now().isoformat(timespec="seconds"),
            }
            text = json.dumps(data, ensure_ascii=False, indent=2)
            fd, tmp = tempfile.mkstemp(dir=self._persist_path.parent, suffix=".tmp")
            try:
                os.write(fd, text.encode("utf-8"))
                os.close(fd)
                os.replace(tmp, str(self._persist_path))
            except Exception:
                try:
                    os.close(fd)
                except Exception:
                    pass
                try:
                    os.unlink(tmp)
                except Exception:
                    pass
                raise
        except Exception as exc:
            logger.warning("[AgentMemory] Не удалось сохранить: %s", exc)

    def _load(self) -> None:
        if not self._persist_path.exists():
            return
        try:
            raw = json.loads(self._persist_path.read_text(encoding="utf-8"))
            self._kv     = raw.get("kv", {})
            self._agents = raw.get("agents", {})
            logger.info("[AgentMemory] Загружена: %d KV, %d агентов",
                        len(self._kv), len(self._agents))
        except Exception as exc:
            logger.warning("[AgentMemory] Не удалось загрузить: %s", exc)

    def reset(self) -> None:
        with self._lock:
            self._kv.clear()
            self._agents.clear()
            self._events.clear()
            self._save()
        logger.info("[AgentMemory] Сброшена.")


# ── Глобальный синглтон ────────────────────────────────────────────────────────

_global_memory: Optional[AgentMemory] = None


def get_memory() -> AgentMemory:
    global _global_memory
    if _global_memory is None:
        _global_memory = AgentMemory()
    return _global_memory
