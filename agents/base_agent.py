"""
agents/base_agent.py — Базовый класс для агентов PreLend.

Паттерн полностью аналогичен ShortsProject/pipeline/agents/base_agent.py:
  - start() / stop() — lifecycle в отдельном потоке
  - sleep(n) — прерываемый sleep (stop() будит поток)
  - _set_status() — обновление статуса в AgentMemory
  - _send()  — отправка Telegram через monitor/notifier
  - report() — сохранение отчёта в AgentMemory

Отличия от ShortsProject:
  - нет GPU/Director/psutil зависимостей
  - _send() использует monitor.notifier напрямую
"""
from __future__ import annotations

import logging
import threading
import time
import traceback
from abc import ABC, abstractmethod
from enum import Enum
from typing import Any, Dict, Optional

from agents.memory import AgentMemory, get_memory


class AgentStatus(Enum):
    IDLE    = "IDLE"
    RUNNING = "RUNNING"
    ERROR   = "ERROR"
    STOPPED = "STOPPED"


class BaseAgent(ABC):
    """
    Базовый класс. Подклассы обязаны реализовать run().

    Паттерн использования в run():
        def run(self) -> None:
            while not self.should_stop:
                self._set_status(AgentStatus.RUNNING, "работаю")
                # ... логика ...
                if not self.sleep(60):
                    break
            self._set_status(AgentStatus.STOPPED)
    """

    def __init__(
        self,
        name:   str,
        memory: Optional[AgentMemory] = None,
        notify: Any = None,
    ) -> None:
        self.name    = name
        self.memory  = memory or get_memory()
        self._notify = notify
        self.status  = AgentStatus.IDLE
        self._last_error:  Optional[str]   = None
        self._start_time:  Optional[float] = None
        self._stop_event   = threading.Event()
        self._thread:      Optional[threading.Thread] = None
        self.logger        = logging.getLogger(f"prelend.agent.{name.lower()}")

        self.memory.register_agent(name)

    # ── Абстрактный метод ─────────────────────────────────────────────────────

    @abstractmethod
    def run(self) -> None:
        """Основная логика агента. Запускается в отдельном потоке."""
        ...

    # ── Lifecycle ─────────────────────────────────────────────────────────────

    def start(self) -> None:
        if self._thread and self._thread.is_alive():
            self.logger.warning("[%s] Уже запущен", self.name)
            return
        self._stop_event.clear()
        self._start_time = time.monotonic()
        self._thread = threading.Thread(
            target=self._run_wrapper,
            name=f"prelend-{self.name.lower()}",
            daemon=True,
        )
        self._thread.start()
        self.logger.info("[%s] Запущен", self.name)

    def stop(self, timeout: float = 10.0) -> None:
        self._stop_event.set()
        if self._thread and self._thread.is_alive():
            self._thread.join(timeout=timeout)
        self._set_status(AgentStatus.STOPPED)
        self.logger.info("[%s] Остановлен", self.name)

    def _run_wrapper(self) -> None:
        try:
            self.run()
        except Exception as exc:
            self._last_error = str(exc)
            self._set_status(AgentStatus.ERROR, str(exc)[:120])
            self.logger.error("[%s] Необработанная ошибка: %s\n%s",
                              self.name, exc, traceback.format_exc())

    # ── Управление потоком ────────────────────────────────────────────────────

    @property
    def should_stop(self) -> bool:
        return self._stop_event.is_set()

    def sleep(self, seconds: float) -> bool:
        """Прерываемый sleep. Возвращает False если вызван stop()."""
        return not self._stop_event.wait(timeout=seconds)

    def get_uptime(self) -> Optional[float]:
        if self._start_time is None:
            return None
        return round(time.monotonic() - self._start_time, 1)

    # ── Статус и отчётность ───────────────────────────────────────────────────

    def _set_status(self, status: AgentStatus, detail: str = "") -> None:
        self.status = status
        status_str  = status.value if not detail else f"{status.value}: {detail}"
        self.memory.set_agent_status(self.name, status_str)

    def report(self, data: Dict[str, Any]) -> None:
        self.memory.set_agent_report(self.name, data)

    def set_human_detail(self, text: str) -> None:
        """Краткое описание текущего действия для панели (ContentHub)."""
        self.memory.set_human_detail(self.name, text)

    def _send(self, message: str) -> None:
        try:
            if callable(self._notify):
                self._notify(message)
            else:
                from monitor.notifier import send
                send(message)
        except Exception as exc:
            self.logger.debug("[%s] Не удалось отправить уведомление: %s", self.name, exc)

    def __repr__(self) -> str:
        return f"<PreLendAgent {self.name} [{self.status.value}]>"
