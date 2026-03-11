"""
monitor/notifier.py — Telegram-уведомления для PreLend.

Переиспользует паттерн ShortsProject:
  - rate limiting (не чаще 2 сек)
  - дедупликация (одно сообщение не чаще 5 мин)
  - thread-safe

Режимы отправки:
  PL_TELEGRAM_CRITICAL_ONLY=false (по умолчанию) — отправляются все уведомления
  PL_TELEGRAM_CRITICAL_ONLY=true  — только критические (лендинг упал, пиковый бот%)
                                     Устанавливается когда Orchestrator берёт на себя
                                     стратегические уведомления (дайджест, шейв-отчёты).

Критические вызовы: alert()  — всегда отправляется.
Обычные вызовы:     send()   — фильтруется при CRITICAL_ONLY=true.

Использование:
    from monitor.notifier import send, alert

    send("📊 Дайджест готов")          # фильтруется при CRITICAL_ONLY=true
    alert("🔴 Лендинг упал: adv_001")  # всегда доставляется
"""
from __future__ import annotations

import hashlib
import logging
import os
import threading
import time

import requests
from dotenv import load_dotenv

load_dotenv()

logger = logging.getLogger(__name__)

TELEGRAM_BOT_TOKEN: str = os.getenv("TELEGRAM_BOT_TOKEN", "")
TELEGRAM_CHAT_ID:   str = os.getenv("TELEGRAM_CHAT_ID", "")

# Если True — отправляем только сообщения с critical=True.
# Orchestrator читает данные напрямую и шлёт дайджест сам.
_CRITICAL_ONLY: bool = os.getenv("PL_TELEGRAM_CRITICAL_ONLY", "false").lower() == "true"

# ── Rate limiter ──────────────────────────────────────────────────────────────
_lock            = threading.Lock()
_last_send_ts    = 0.0
_min_interval    = 2.0          # минимум 2 сек между сообщениями
_dedup_cache:    dict = {}      # hash → timestamp
_dedup_window    = 300          # дедупликация 5 мин


def send(message: str, parse_mode: str = "HTML", critical: bool = False) -> bool:
    """
    Отправить сообщение в Telegram.
    Возвращает True при успехе.

    Args:
        critical: если True — отправляется всегда (даже при PL_TELEGRAM_CRITICAL_ONLY=true).
                  Используется для алертов о падении лендингов и пиковом боте.
    """
    if not TELEGRAM_BOT_TOKEN or not TELEGRAM_CHAT_ID:
        logger.warning("[Notifier] Telegram не настроен (проверь .env)")
        return False

    # Фильтр: при CRITICAL_ONLY пропускаем некритичные (дайджест, шейв-отчёты, тест-конверсии)
    if _CRITICAL_ONLY and not critical:
        logger.debug("[Notifier] Пропущено (PL_TELEGRAM_CRITICAL_ONLY=true, critical=False)")
        return True  # не ошибка — Orchestrator возьмёт эти данные напрямую

    with _lock:
        global _last_send_ts

        msg_hash = hashlib.md5(message[:200].encode()).hexdigest()
        now = time.monotonic()

        # Дедупликация
        if now - _dedup_cache.get(msg_hash, 0.0) < _dedup_window:
            logger.debug("[Notifier] Дубль — пропущен")
            return True

        # Rate limit
        wait = _min_interval - (now - _last_send_ts)
        if wait > 0:
            time.sleep(wait)

        _last_send_ts = time.monotonic()
        _dedup_cache[msg_hash] = _last_send_ts

        # Чистим устаревшие записи кеша
        cutoff  = _last_send_ts - 600
        expired = [k for k, v in _dedup_cache.items() if v < cutoff]
        for k in expired:
            del _dedup_cache[k]

    try:
        url  = f"https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}/sendMessage"
        resp = requests.post(
            url,
            json={"chat_id": TELEGRAM_CHAT_ID, "text": message, "parse_mode": parse_mode},
            timeout=10,
        )
        ok = resp.status_code == 200
        if not ok:
            logger.warning("[Notifier] Telegram %d: %s", resp.status_code, resp.text[:200])
        return ok
    except Exception as exc:
        logger.error("[Notifier] Ошибка отправки: %s", exc)
        return False


def alert(message: str, parse_mode: str = "HTML") -> bool:
    """
    Критический алерт — всегда доставляется, игнорирует PL_TELEGRAM_CRITICAL_ONLY.
    Используй для: лендинг упал, пиковый бот%, критические ошибки мониторинга.
    """
    return send(message, parse_mode=parse_mode, critical=True)
