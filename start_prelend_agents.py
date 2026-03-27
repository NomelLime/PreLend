"""
start_prelend_agents.py — один процесс: все агенты PreLend (COMMANDER, ANALYST, MONITOR, OFFER_ROTATOR).

Без ShortsProject и без Telegram polling (команды через Orchestrator или отдельный telegram_router).

  python start_prelend_agents.py

Ctrl+C — корректная остановка агентов.
"""
from __future__ import annotations

import logging
import signal
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from dotenv import load_dotenv

load_dotenv()

from telegram_router import PreLendCrew, make_notify, TG_CHAT_ID, TG_TOKEN

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [prelend] %(levelname)s %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("start_prelend_agents")


def main() -> None:
    notify = make_notify(TG_TOKEN, TG_CHAT_ID) if (TG_TOKEN and TG_CHAT_ID) else lambda _m: None
    prelend = PreLendCrew(notify=notify)

    def _shutdown(sig, frame) -> None:
        logger.info("Сигнал %s — останавливаю агентов...", sig)
        prelend.stop()
        sys.exit(0)

    signal.signal(signal.SIGINT, _shutdown)
    signal.signal(signal.SIGTERM, _shutdown)

    prelend.start()
    logger.info("PreLend агенты запущены — Ctrl+C для остановки")
    try:
        while True:
            time.sleep(60)
    except KeyboardInterrupt:
        pass
    prelend.stop()


if __name__ == "__main__":
    main()
