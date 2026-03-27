"""
telegram_router.py — Единый Telegram polling для ShortsProject + PreLend.

Запуск (на ПК разработчика, рядом с ShortsProject):
  python telegram_router.py              → polling + интерактивный CLI
  python telegram_router.py --daemon     → только фоновый режим
  python telegram_router.py --cmd "/prelend статус"  → одна команда и выход

Маршрутизация входящих сообщений:
  /prelend <команда>  →  PreLend COMMANDER
  /shorts  <команда>  →  ShortsProject COMMANDER
  <без префикса>      →  PreLend COMMANDER (дефолт)

Архитектура:
  ┌─────────────────────────────────────┐
  │          telegram_router.py         │
  │                                     │
  │  polling_loop()                     │
  │    ├─ /prelend → PreLend.command()  │
  │    └─ /shorts  → Shorts.command()   │
  │                                     │
│  PreLendCrew    ShortsProjectCrew   │
│  (COMMANDER     (уже существует     │
│   ANALYST        в ShortsProject)   │
│   MONITOR, OFFER_ROTATOR)           │
  └─────────────────────────────────────┘

.env (общий файл):
  TELEGRAM_BOT_TOKEN=...
  TELEGRAM_CHAT_ID=...
  TELEGRAM_ALLOWED_USER_IDS=123456,789012  (опционально)
  SHORTS_PROJECT_PATH=/path/to/ShortsProject
  PRELEND_PATH=/path/to/PreLend
"""
from __future__ import annotations

import argparse
import logging
import os
import signal
import sys
import threading
import time
from pathlib import Path
from typing import Callable, Optional

import requests
from dotenv import load_dotenv

load_dotenv()

# ── Пути к проектам ───────────────────────────────────────────────────────────
PRELEND_PATH  = Path(os.getenv("PRELEND_PATH",  str(Path(__file__).resolve().parent)))
SHORTS_PATH   = Path(os.getenv("SHORTS_PROJECT_PATH", ""))

# PYTHONPATH — добавляем оба проекта
sys.path.insert(0, str(PRELEND_PATH))
if SHORTS_PATH.exists():
    sys.path.insert(0, str(SHORTS_PATH))

# ── Логирование ───────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [router] %(levelname)s %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("telegram_router")

# ── Env ───────────────────────────────────────────────────────────────────────
TG_TOKEN       = os.getenv("TELEGRAM_BOT_TOKEN", "")
TG_CHAT_ID     = os.getenv("TELEGRAM_CHAT_ID", "")
ALLOWED_USERS  = set(
    x.strip()
    for x in os.getenv("TELEGRAM_ALLOWED_USER_IDS", "").split(",")
    if x.strip()
)

# PL_DISABLE_TELEGRAM_POLLING=true → Orchestrator единственный принимает команды.
# PreLend продолжает слать критические алерты (notifier.alert()), но не читает входящие.
_DISABLE_POLLING: bool = os.getenv("PL_DISABLE_TELEGRAM_POLLING", "false").lower() == "true"


# ══════════════════════════════════════════════════════════════════════════════
# PreLend Crew — обёртка над тремя агентами
# ══════════════════════════════════════════════════════════════════════════════

class PreLendCrew:
    """
    Инициализирует и управляет агентами PreLend:
      COMMANDER, ANALYST, MONITOR, OFFER_ROTATOR
    """

    def __init__(self, notify: Optional[Callable[[str], None]] = None) -> None:
        from agents.memory       import get_memory
        from agents.commander    import Commander
        from agents.analyst      import Analyst
        from agents.monitor_agent import Monitor
        from agents.offer_rotator import OfferRotator

        self.memory    = get_memory()
        self._notify   = notify

        self.analyst   = Analyst(memory=self.memory,   notify=notify)
        self.monitor   = Monitor(memory=self.memory,   notify=notify,
                                 analyst=self.analyst)
        self.offer_rotator = OfferRotator(memory=self.memory, notify=notify)
        self.commander = Commander(memory=self.memory, notify=notify)

        logger.info("[PreLend] Агенты инициализированы")

    def start(self) -> None:
        self.analyst.start()
        self.monitor.start()
        self.offer_rotator.start()
        self.commander.start()
        logger.info("[PreLend] Все агенты запущены")

    def stop(self) -> None:
        self.commander.stop()
        self.offer_rotator.stop()
        self.monitor.stop()
        self.analyst.stop()
        logger.info("[PreLend] Все агенты остановлены")

    def command(self, text: str) -> str:
        """Обработать команду через COMMANDER и вернуть ответ."""
        return self.commander.handle_command(text)


# ══════════════════════════════════════════════════════════════════════════════
# ShortsProject Crew — обёртка (опциональная)
# ══════════════════════════════════════════════════════════════════════════════

class ShortsCrew:
    """
    Тонкая обёртка над ShortsProjectCrew.
    Если ShortsProject недоступен — все команды возвращают предупреждение.
    """

    def __init__(self, notify: Optional[Callable[[str], None]] = None) -> None:
        self._crew   = None
        self._notify = notify

        try:
            from pipeline.crew import ShortsProjectCrew  # type: ignore
            self._crew = ShortsProjectCrew(notify=notify)
            logger.info("[Shorts] ShortsProjectCrew инициализирован")
        except Exception as exc:
            logger.warning("[Shorts] Недоступен: %s — команды /shorts будут отклонены", exc)

    def start(self) -> None:
        if self._crew:
            self._crew.start()

    def stop(self) -> None:
        if self._crew:
            self._crew.stop()

    def command(self, text: str) -> str:
        if self._crew:
            return self._crew.command(text)
        return "⚠️ ShortsProject недоступен на этой машине."

    @property
    def available(self) -> bool:
        return self._crew is not None


# ══════════════════════════════════════════════════════════════════════════════
# Telegram helpers
# ══════════════════════════════════════════════════════════════════════════════

def tg_send(token: str, chat_id: str, text: str) -> None:
    """Отправляет сообщение в Telegram. Молча игнорирует ошибки."""
    try:
        requests.post(
            f"https://api.telegram.org/bot{token}/sendMessage",
            json={"chat_id": chat_id, "text": text, "parse_mode": "HTML"},
            timeout=10,
        )
    except Exception as exc:
        logger.debug("[tg_send] Ошибка: %s", exc)


def make_notify(token: str, chat_id: str) -> Callable[[str], None]:
    """Фабрика notify-функции для агентов."""
    def _notify(msg: str) -> None:
        tg_send(token, chat_id, msg)
    return _notify


# ══════════════════════════════════════════════════════════════════════════════
# Роутер сообщений
# ══════════════════════════════════════════════════════════════════════════════

def route_message(
    text:       str,
    prelend:    PreLendCrew,
    shorts:     ShortsCrew,
    notify:     Callable[[str], None],
) -> None:
    """
    Маршрутизирует входящее сообщение нужному COMMANDER-у.
    Ответ отправляет обратно в Telegram.
    """
    text = text.strip()

    # /prelend <команда>
    if text.lower().startswith("/prelend"):
        cmd    = text[len("/prelend"):].strip()
        result = prelend.command(cmd) if cmd else prelend.command("помощь")
        notify(result)
        return

    # /shorts <команда>
    if text.lower().startswith("/shorts"):
        cmd    = text[len("/shorts"):].strip()
        result = shorts.command(cmd) if cmd else shorts.command("помощь")
        notify(result)
        return

    # Системные команды роутера
    if text.lower() in ("/start", "/help", "помощь роутер"):
        notify(_router_help(shorts.available))
        return

    # Без префикса — дефолт → PreLend
    result = prelend.command(text)
    notify(result)


def _router_help(shorts_available: bool) -> str:
    shorts_status = "✅ доступен" if shorts_available else "⚠️ недоступен"
    return (
        "🤖 <b>Telegram Router — PreLend + ShortsProject</b>\n\n"
        "<b>Префиксы команд:</b>\n"
        "  <code>/prelend &lt;команда&gt;</code> — PreLend агент\n"
        "  <code>/shorts  &lt;команда&gt;</code> — ShortsProject агент\n\n"
        "<b>Примеры:</b>\n"
        "  <code>/prelend статус</code>\n"
        "  <code>/prelend рекламодатели</code>\n"
        "  <code>/prelend ставка adv_001 5.0</code>\n"
        "  <code>/shorts статус</code>\n\n"
        f"ShortsProject: {shorts_status}\n"
        "Без префикса → PreLend"
    )


# ══════════════════════════════════════════════════════════════════════════════
# Polling loop
# ══════════════════════════════════════════════════════════════════════════════

def polling_loop(
    prelend: PreLendCrew,
    shorts:  ShortsCrew,
    token:   str,
    chat_id: str,
) -> None:
    """
    Бесконечный long-polling Telegram.
    Работает в отдельном daemon-потоке.
    """
    notify = make_notify(token, chat_id)
    offset = 0

    logger.info("[Polling] Запущен (chat_id=%s)", chat_id)

    while True:
        try:
            resp = requests.get(
                f"https://api.telegram.org/bot{token}/getUpdates",
                params={"offset": offset, "timeout": 30},
                timeout=40,
            )
            if resp.status_code != 200:
                logger.warning("[Polling] HTTP %s", resp.status_code)
                time.sleep(5)
                continue

            updates = resp.json().get("result", [])

            for upd in updates:
                offset = upd["update_id"] + 1
                msg    = upd.get("message", {})

                # Фильтр по chat_id
                if str(msg.get("chat", {}).get("id", "")) != str(chat_id):
                    continue

                # Whitelist отправителей
                sender_id = str(msg.get("from", {}).get("id", ""))
                if ALLOWED_USERS and sender_id not in ALLOWED_USERS:
                    logger.warning("[Polling] Неавторизованный user_id=%s — игнор", sender_id)
                    continue

                text = msg.get("text", "").strip()
                if not text:
                    continue

                logger.info("[Polling] user=%s: %s", sender_id, text[:80])

                # Роутинг в отдельном потоке — не блокируем polling
                threading.Thread(
                    target=_safe_route,
                    args=(text, prelend, shorts, notify),
                    daemon=True,
                ).start()

        except Exception as exc:
            logger.debug("[Polling] Ошибка: %s", exc)
            time.sleep(5)


def _safe_route(
    text:    str,
    prelend: PreLendCrew,
    shorts:  ShortsCrew,
    notify:  Callable[[str], None],
) -> None:
    """Обёртка маршрутизатора с перехватом ошибок."""
    try:
        route_message(text, prelend, shorts, notify)
    except Exception as exc:
        logger.error("[Router] Ошибка при обработке '%s': %s", text[:50], exc)
        try:
            notify(f"❌ Внутренняя ошибка роутера: {exc}")
        except Exception:
            pass


# ══════════════════════════════════════════════════════════════════════════════
# CLI
# ══════════════════════════════════════════════════════════════════════════════

def interactive_cli(prelend: PreLendCrew, shorts: ShortsCrew) -> None:
    """Интерактивный CLI — дублирует логику routing без Telegram."""
    notify = lambda msg: print(f"\n{msg}\n")

    print("\n" + "=" * 60)
    print("  PreLend + ShortsProject COMMANDER CLI")
    print("  /prelend <cmd>  |  /shorts <cmd>  |  /help  |  выход")
    print("=" * 60 + "\n")

    while True:
        try:
            text = input(">>> ").strip()
        except (EOFError, KeyboardInterrupt):
            print("\nВыход...")
            break

        if not text:
            continue
        if text.lower() in ("выход", "exit", "quit"):
            break

        route_message(text, prelend, shorts, notify)


# ══════════════════════════════════════════════════════════════════════════════
# main
# ══════════════════════════════════════════════════════════════════════════════

def main() -> None:
    parser = argparse.ArgumentParser(description="PreLend + ShortsProject Telegram Router")
    parser.add_argument("--daemon",       action="store_true", help="Фоновый режим без CLI")
    parser.add_argument("--no-telegram",  action="store_true", help="Без polling (только CLI)")
    parser.add_argument("--no-shorts",    action="store_true", help="Не инициализировать ShortsProject")
    parser.add_argument("--cmd",          type=str, default="", help="Одна команда и выход")
    args = parser.parse_args()

    # ── Проверка конфига ──────────────────────────────────────────────────────
    if not TG_TOKEN or not TG_CHAT_ID:
        logger.warning(
            "TELEGRAM_BOT_TOKEN или TELEGRAM_CHAT_ID не заданы — "
            "Telegram polling недоступен, только CLI"
        )

    notify = make_notify(TG_TOKEN, TG_CHAT_ID) if (TG_TOKEN and TG_CHAT_ID) else lambda m: None

    # ── Инициализация Crews ───────────────────────────────────────────────────
    logger.info("Инициализация PreLend...")
    prelend = PreLendCrew(notify=notify)

    logger.info("Инициализация ShortsProject...")
    shorts  = ShortsCrew(notify=notify) if not args.no_shorts else ShortsCrew.__new__(ShortsCrew)
    if args.no_shorts:
        shorts._crew   = None
        shorts._notify = notify

    # ── Graceful shutdown ─────────────────────────────────────────────────────
    def _shutdown(sig, frame) -> None:
        logger.info("Сигнал %s — останавливаю агентов...", sig)
        prelend.stop()
        shorts.stop()
        sys.exit(0)

    signal.signal(signal.SIGINT,  _shutdown)
    signal.signal(signal.SIGTERM, _shutdown)

    # ── Запуск агентов ────────────────────────────────────────────────────────
    prelend.start()
    shorts.start()

    # ── Режим одной команды ───────────────────────────────────────────────────
    if args.cmd:
        route_message(args.cmd, prelend, shorts, lambda m: print(m))
        prelend.stop()
        shorts.stop()
        return

    # ── Polling ───────────────────────────────────────────────────────────────
    polling_started = False
    if TG_TOKEN and TG_CHAT_ID and not args.no_telegram and not _DISABLE_POLLING:
        t = threading.Thread(
            target=polling_loop,
            args=(prelend, shorts, TG_TOKEN, TG_CHAT_ID),
            daemon=True,
            name="tg-polling",
        )
        t.start()
        polling_started = True
        logger.info("Telegram polling поток запущен")
    elif _DISABLE_POLLING:
        logger.info("Telegram polling отключён (PL_DISABLE_TELEGRAM_POLLING=true)"
                    " — команды принимает Orchestrator")
    else:
        logger.info("Telegram polling отключён")

    # ── Daemon или CLI ────────────────────────────────────────────────────────
    if args.daemon:
        logger.info("Daemon режим — Ctrl+C для выхода")
        try:
            while True:
                time.sleep(60)
        except KeyboardInterrupt:
            pass
    else:
        interactive_cli(prelend, shorts)

    prelend.stop()
    shorts.stop()


if __name__ == "__main__":
    main()
