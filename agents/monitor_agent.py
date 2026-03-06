"""
agents/monitor_agent.py — MONITOR PreLend.

Паттерн унаследован от SENTINEL (ShortsProject):
  - фоновый поток, цикл каждые 5 минут
  - проверяет uptime лендингов (вызывает health_check логику)
  - распознаёт паттерны падений (плановые работы vs реальные проблемы)
  - пересчитывает Score рекламодателей
  - триггерит ANALYST при аномальных паттернах CR
  - алерты: 🤖 высокий % ботов, 🌍 нецелевой трафик

Отличия от SENTINEL:
  - нет GPU/CPU/RAM мониторинга
  - работает с PreLend SQLite напрямую
  - мониторит внешние лендинги, а не внутренние агенты
"""
from __future__ import annotations

import json
import logging
import sqlite3
import time
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

import requests

from agents.base_agent import BaseAgent, AgentStatus
from agents.memory import AgentMemory, get_memory

logger = logging.getLogger(__name__)

ROOT     = Path(__file__).resolve().parent.parent
CFG_ADV  = ROOT / "config" / "advertisers.json"
CFG_SET  = ROOT / "config" / "settings.json"

_CHECK_INTERVAL = 300   # 5 минут
_REQUEST_TIMEOUT = 8    # секунд на HEAD-запрос


class Monitor(BaseAgent):
    """
    Мониторит доступность лендингов и качество трафика.

    Логика паттернов падений:
      - Падение < 10 мин → вероятно случайный сбой, ждём
      - Падение 10-60 мин → алерт + пересчёт Score (рекл. опускается вниз)
      - Падение > 60 мин → повторный алерт + триггер ANALYST
      - Падение в ночное время (02:00-06:00 UTC) → может быть плановое ТО
    """

    def __init__(
        self,
        memory:  Optional[AgentMemory] = None,
        notify:  Any = None,
        analyst: Any = None,   # Analyst — опциональная ссылка для триггера
    ) -> None:
        super().__init__("MONITOR", memory, notify)
        self.analyst = analyst

        # Паттерн падений: adv_id → {since: float, alerted_10m: bool, alerted_60m: bool}
        self._down_since:  Dict[str, Dict] = {}
        # Cooldown алертов трафика (bot%, offgeo%)
        self._alert_cooldown: Dict[str, float] = {}
        self._alert_interval  = 3600   # 1 час между однотипными алертами

    # ── run() ─────────────────────────────────────────────────────────────────

    def run(self) -> None:
        self.logger.info("[MONITOR] Запущен (интервал %ds)", _CHECK_INTERVAL)
        while not self.should_stop:
            self._set_status(AgentStatus.RUNNING, "проверка")
            try:
                self._monitor_cycle()
            except Exception as exc:
                self.logger.error("[MONITOR] Ошибка цикла: %s", exc)
            finally:
                self._set_status(AgentStatus.IDLE)

            if not self.sleep(_CHECK_INTERVAL):
                break

    # ── Основной цикл ─────────────────────────────────────────────────────────

    def _monitor_cycle(self) -> None:
        advertisers = self._load_advertisers()
        settings    = self._load_settings()
        db_path     = settings.get("db_path", str(ROOT / "data" / "clicks.db"))

        conn = sqlite3.connect(db_path)
        conn.row_factory = sqlite3.Row

        for adv in advertisers:
            if adv.get("status") != "active":
                continue
            self._check_landing(adv, conn)

        self._check_traffic_quality(conn, settings)
        conn.close()

        # Обновляем отчёт агента
        self.report({
            "last_check":  datetime.utcnow().isoformat(timespec="seconds"),
            "down_count":  len(self._down_since),
        })

    # ── Проверка лендинга ─────────────────────────────────────────────────────

    def _check_landing(self, adv: Dict, conn: sqlite3.Connection) -> None:
        adv_id   = adv["id"]
        adv_name = adv["name"]
        url      = adv["url"]

        is_up, ms = self._head_request(url)
        uptime_24h = self._calc_uptime(conn, adv_id)

        self._update_landing_status(conn, adv_id, is_up, ms, uptime_24h)
        conn.commit()

        now = time.time()

        if not is_up:
            # ── Лендинг лежит ─────────────────────────────────────────────
            if adv_id not in self._down_since:
                self._down_since[adv_id] = {
                    "since":       now,
                    "alerted_10m": False,
                    "alerted_60m": False,
                }
                self.logger.warning("[MONITOR] %s (%s) — лендинг недоступен", adv_id, adv_name)

            down_info = self._down_since[adv_id]
            down_sec  = now - down_info["since"]
            is_night  = 2 <= datetime.utcnow().hour < 6

            if down_sec >= 600 and not down_info["alerted_10m"]:
                note = "(возможно плановые работы)" if is_night else ""
                self._send(
                    f"🔴 <b>Лендинг упал!</b> {note}\n"
                    f"Рекламодатель: <b>{adv_name}</b> (<code>{adv_id}</code>)\n"
                    f"Недоступен: {int(down_sec // 60)} мин\n"
                    f"Uptime 24h: {uptime_24h:.1f}%"
                )
                down_info["alerted_10m"] = True

            if down_sec >= 3600 and not down_info["alerted_60m"]:
                self._send(
                    f"🔴 <b>Лендинг не работает > 1 часа!</b>\n"
                    f"Рекламодатель: <b>{adv_name}</b>\n"
                    f"Недоступен: {int(down_sec // 60)} мин — проверь рекламодателя"
                )
                down_info["alerted_60m"] = True
                # Триггер ANALYST
                self._trigger_analyst()

        else:
            # ── Лендинг доступен ──────────────────────────────────────────
            if adv_id in self._down_since:
                down_sec = now - self._down_since[adv_id]["since"]
                self._send(
                    f"🟢 <b>Лендинг восстановлен</b>\n"
                    f"Рекламодатель: <b>{adv_name}</b>\n"
                    f"Простой: {int(down_sec // 60)} мин | Ответ: {ms} мс"
                )
                del self._down_since[adv_id]

            # Алерт на медленный ответ
            slow_ms = int(self._load_settings().get("alerts", {}).get("landing_slow_ms", 2000))
            if ms > slow_ms and self._can_alert(f"slow_{adv_id}"):
                self._send(
                    f"🟡 <b>Лендинг медленно отвечает</b>\n"
                    f"Рекламодатель: <b>{adv_name}</b>\n"
                    f"Время ответа: <b>{ms} мс</b> (порог: {slow_ms} мс)"
                )

    # ── Качество трафика ──────────────────────────────────────────────────────

    def _check_traffic_quality(
        self, conn: sqlite3.Connection, settings: Dict
    ) -> None:
        """Проверяет % ботов и нецелевого трафика за последний час."""
        since     = int(time.time()) - 3600
        alerts    = settings.get("alerts", {})
        bot_thr   = int(alerts.get("bot_pct_per_hour",    40))
        offgeo_thr = int(alerts.get("offgeo_pct_per_hour", 60))

        try:
            row = conn.execute("""
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'bot'                 THEN 1 ELSE 0 END) AS bots,
                    SUM(CASE WHEN status IN ('bot','cloaked')    THEN 1 ELSE 0 END) AS offgeo
                FROM clicks
                WHERE ts >= ? AND is_test = 0
            """, (since,)).fetchone()

            total  = row["total"]  or 0
            bots   = row["bots"]   or 0
            offgeo = row["offgeo"] or 0

            if total < 10:
                return  # слишком мало данных

            bot_pct    = bots   / total * 100
            offgeo_pct = offgeo / total * 100

            if bot_pct >= bot_thr and self._can_alert("bot_pct"):
                self._send(
                    f"🤖 <b>Высокий % ботов за последний час!</b>\n"
                    f"Ботов: <b>{bot_pct:.1f}%</b> (порог: {bot_thr}%)\n"
                    f"Всего кликов: {total}"
                )
                self.memory.log_event("MONITOR", "high_bots",
                                      {"bot_pct": bot_pct, "total": total})

            if offgeo_pct >= offgeo_thr and self._can_alert("offgeo_pct"):
                self._send(
                    f"🌍 <b>Нецелевой трафик превысил порог!</b>\n"
                    f"Нецелевых: <b>{offgeo_pct:.1f}%</b> (порог: {offgeo_thr}%)\n"
                    f"Всего кликов: {total}"
                )
                self.memory.log_event("MONITOR", "high_offgeo",
                                      {"offgeo_pct": offgeo_pct, "total": total})

        except Exception as exc:
            self.logger.warning("[MONITOR] Ошибка проверки трафика: %s", exc)

    # ── Вспомогательные методы ────────────────────────────────────────────────

    def _head_request(self, url: str) -> Tuple[bool, int]:
        try:
            start = time.time()
            resp  = requests.head(
                url, timeout=_REQUEST_TIMEOUT, allow_redirects=True,
                headers={"User-Agent": "PreLend-Monitor/1.0"}
            )
            ms    = int((time.time() - start) * 1000)
            return resp.status_code < 500, ms
        except requests.Timeout:
            return False, _REQUEST_TIMEOUT * 1000
        except Exception as exc:
            self.logger.debug("[MONITOR] HEAD %s: %s", url, exc)
            return False, -1

    def _calc_uptime(self, conn: sqlite3.Connection, adv_id: str) -> float:
        row = conn.execute(
            "SELECT is_up, uptime_24h FROM landing_status WHERE advertiser_id = ?",
            (adv_id,)
        ).fetchone()
        if row is None:
            return 100.0
        weight  = 1 / 288
        new_val = float(row["uptime_24h"]) * (1 - weight) + (100.0 if row["is_up"] else 0.0) * weight
        return round(new_val, 2)

    def _update_landing_status(
        self, conn: sqlite3.Connection,
        adv_id: str, is_up: bool, ms: int, uptime: float
    ) -> None:
        conn.execute("""
            INSERT INTO landing_status (advertiser_id, last_check, response_ms, is_up, uptime_24h)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(advertiser_id) DO UPDATE SET
                last_check  = excluded.last_check,
                response_ms = excluded.response_ms,
                is_up       = excluded.is_up,
                uptime_24h  = excluded.uptime_24h
        """, (adv_id, int(time.time()), ms, int(is_up), uptime))

    def _can_alert(self, key: str) -> bool:
        """Проверяет cooldown: не чаще одного алерта одного типа в час."""
        now = time.time()
        if now - self._alert_cooldown.get(key, 0) >= self._alert_interval:
            self._alert_cooldown[key] = now
            return True
        return False

    def _trigger_analyst(self) -> None:
        """Триггерит ANALYST если он подключён."""
        if self.analyst:
            self.logger.info("[MONITOR] Триггер → ANALYST")
            self.memory.set("analyst_trigger", {
                "reason": "landing_down_60m",
                "ts":     int(time.time()),
            })
            try:
                self.analyst.enqueue_analysis()
            except Exception as exc:
                self.logger.debug("[MONITOR] Триггер ANALYST: %s", exc)

    def _load_advertisers(self) -> List[Dict]:
        with open(CFG_ADV, encoding="utf-8") as f:
            return json.load(f)

    def _load_settings(self) -> Dict:
        with open(CFG_SET, encoding="utf-8") as f:
            return json.load(f)
