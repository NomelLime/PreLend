"""
agents/commander.py — COMMANDER PreLend.

Принимает команды через Telegram (/prelend <команда>),
разбирает их через Ollama (с fallback-парсером),
исполняет напрямую через SQLite и конфиг-файлы.

Поддерживаемые команды:
  статус                        — состояние системы
  рекламодатели                 — список активных + Score
  ставка <id> <число>           — изменить ставку рекламодателя
  добавить рекламодателя <json> — добавить запись в advertisers.json
  отчёт [N]                     — дайджест за последние N дней (дефолт 1)
  тест отправить                — запустить test_conversions send
  тест отчёт                    — запустить test_conversions report
  порог шейва <число>           — изменить shave_threshold_pct
  порог ботов <число>           — изменить bot_pct_per_hour
  история                       — последние 10 команд
  помощь                        — список команд

Архитектура:
  handle_command(text) — вызывается из telegram_router.py
    → _quick_command()      — быстрые команды без LLM
    → _parse_with_ollama()  — Ollama парсинг намерения
    → _fallback_parse()     — regex-парсер если Ollama недоступна
    → _execute()            — исполнение
"""
from __future__ import annotations

import json
import logging
import re
import sqlite3
import threading
import time
from collections import deque
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, List, Optional

from agents.base_agent import BaseAgent, AgentStatus
from agents.memory import AgentMemory, get_memory

logger = logging.getLogger(__name__)

ROOT     = Path(__file__).resolve().parent.parent
CFG_ADV  = ROOT / "config" / "advertisers.json"
CFG_SET  = ROOT / "config" / "settings.json"

# ── Ollama prompt ──────────────────────────────────────────────────────────────
_PARSE_PROMPT = """Ты — помощник системы управления affiliate-трафиком PreLend.
Тебе дана команда оператора. Ответь ТОЛЬКО валидным JSON без пояснений и markdown.

Команда: {command}

Формат ответа:
{{
  "intent": "одно из: status|list_adv|set_rate|add_adv|report|test_send|test_report|set_threshold|history|help|custom",
  "params": {{"ключ": "значение"}},
  "advice": "совет оператору (1 предложение, на русском)",
  "requires_confirmation": false
}}

requires_confirmation = true только если команда меняет ставку или добавляет рекламодателя."""


class CommandResult:
    def __init__(self, command: str, intent: str, executed: bool, message: str) -> None:
        self.command  = command
        self.intent   = intent
        self.executed = executed
        self.message  = message
        self.ts       = datetime.now().isoformat(timespec="seconds")

    def to_dict(self) -> Dict:
        return {
            "command":  self.command,
            "intent":   self.intent,
            "executed": self.executed,
            "message":  self.message,
            "ts":       self.ts,
        }


class Commander(BaseAgent):

    VALID_INTENTS = frozenset({
        "status", "list_adv", "set_rate", "add_adv", "report",
        "test_send", "test_report", "set_threshold", "history", "help", "custom",
    })

    def __init__(
        self,
        memory:       Optional[AgentMemory] = None,
        notify:       Any = None,
        auto_confirm: bool = False,
    ) -> None:
        super().__init__("COMMANDER", memory, notify)
        self.auto_confirm      = auto_confirm
        self._queue:           deque = deque()
        self._queue_lock       = threading.Lock()
        self._history:         List[CommandResult] = []
        self._pending_confirm: Optional[Dict] = None

    # ── Публичный API ──────────────────────────────────────────────────────────

    def handle_command(self, command: str, confirmed: bool = False) -> str:
        command = command.strip()
        if not command:
            return "⚠️ Пустая команда"

        self.logger.info("[COMMANDER] Команда: %s", command)
        self.memory.log_event("COMMANDER", "command_received", {"command": command})

        # Подтверждение / отмена ожидающей команды
        if self._pending_confirm:
            low = command.lower()
            if low in ("да", "yes", "y", "подтверди", "ок"):
                return self._execute_pending()
            if low in ("нет", "no", "n", "отмена", "отменить"):
                self._pending_confirm = None
                return "✅ Команда отменена"

        # Быстрые команды без LLM
        quick = self._quick_command(command)
        if quick is not None:
            return quick

        # Парсинг через Ollama (или fallback)
        try:
            parsed = self._parse(command)
        except Exception as exc:
            self.logger.error("[COMMANDER] Ошибка парсинга: %s", exc)
            return f"❌ Не удалось разобрать команду: {exc}"

        advice   = parsed.get("advice", "")
        requires = parsed.get("requires_confirmation", False)

        parts = []
        if advice:
            parts.append(f"💡 {advice}")

        if requires and not confirmed and not self.auto_confirm:
            self._pending_confirm = {"command": command, "parsed": parsed}
            parts.append("❓ Требуется подтверждение. Напишите «да» или «нет».")
            return "\n".join(parts)

        result = self._execute(command, parsed)
        parts.append(result.message)

        self._history.append(result)
        self.memory.set_agent_report("COMMANDER", {
            "last_command": command,
            "last_result":  result.to_dict(),
        })
        return "\n".join(parts)

    def enqueue(self, command: str) -> None:
        """Добавить команду в очередь (вызывается из telegram_router)."""
        with self._queue_lock:
            self._queue.append(command)

    # ── run() — фоновый цикл ──────────────────────────────────────────────────

    def run(self) -> None:
        self.logger.info("[COMMANDER] Ожидание команд...")
        while not self.should_stop:
            with self._queue_lock:
                cmd = self._queue.popleft() if self._queue else None
            if cmd:
                try:
                    result = self.handle_command(cmd)
                    self._send(result)
                except Exception as exc:
                    self.logger.error("[COMMANDER] Ошибка обработки '%s': %s", cmd, exc)
            self.sleep(1.0)

    # ── Быстрые команды (без Ollama) ──────────────────────────────────────────

    def _quick_command(self, command: str) -> Optional[str]:
        low = command.lower()

        if low in ("статус", "status", "/status"):
            return self._cmd_status()

        if low in ("помощь", "help", "/help", "?"):
            return self._cmd_help()

        if low in ("история", "history", "/history"):
            return self._cmd_history()

        if low in ("рекламодатели", "advertisers", "/advertisers"):
            return self._cmd_list_adv()

        return None

    # ── Парсинг ───────────────────────────────────────────────────────────────

    def _parse(self, command: str) -> Dict:
        """Пробует Ollama, при недоступности — fallback."""
        if self.memory.get("ollama_available") is False:
            return self._fallback_parse(command)

        try:
            import ollama  # type: ignore
            settings   = self._load_settings()
            model      = settings.get("ollama", {}).get("model", "qwen2.5-vl:7b")
            ollama_url = settings.get("ollama", {}).get("host", "http://127.0.0.1:11434")

            prompt   = _PARSE_PROMPT.format(command=command)
            response = ollama.generate(
                model=model,
                prompt=prompt,
                options={"temperature": 0.1},
            )
            raw = response.get("response", "{}").strip()
            raw = raw.lstrip("```json").lstrip("```").rstrip("```").strip()

            parsed = json.loads(raw)

            if parsed.get("intent") not in self.VALID_INTENTS:
                parsed["intent"] = "custom"
            parsed["requires_confirmation"] = bool(parsed.get("requires_confirmation", False))
            return parsed

        except ImportError:
            self.logger.debug("[COMMANDER] ollama не установлен — fallback")
        except json.JSONDecodeError:
            self.logger.warning("[COMMANDER] Ollama вернул не-JSON — fallback")
        except Exception as exc:
            self.logger.warning("[COMMANDER] Ollama недоступна: %s — fallback", exc)
            self.memory.set("ollama_available", False)

        return self._fallback_parse(command)

    def _fallback_parse(self, command: str) -> Dict:
        """Regex-парсер для базовых команд без LLM."""
        low = command.lower()

        # ставка adv_001 4.50
        m = re.match(r"ставк[аи]\s+(\S+)\s+([\d.]+)", low)
        if m:
            return {"intent": "set_rate",
                    "params": {"adv_id": m.group(1), "rate": float(m.group(2))},
                    "advice": "", "requires_confirmation": True}

        # отчёт [7]
        m = re.match(r"отч[её]т\s*(\d*)", low)
        if m:
            return {"intent": "report",
                    "params": {"days": int(m.group(1)) if m.group(1) else 1},
                    "advice": "", "requires_confirmation": False}

        # порог шейва / ботов
        m = re.match(r"порог\s+(шейва|ботов|трафика)\s+(\d+)", low)
        if m:
            return {"intent": "set_threshold",
                    "params": {"type": m.group(1), "value": int(m.group(2))},
                    "advice": "", "requires_confirmation": False}

        if "тест" in low and "отч" in low:
            return {"intent": "test_report", "params": {}, "advice": "",
                    "requires_confirmation": False}

        if "тест" in low:
            return {"intent": "test_send", "params": {}, "advice": "",
                    "requires_confirmation": False}

        if any(w in low for w in ("список", "рекламодател")):
            return {"intent": "list_adv", "params": {}, "advice": "",
                    "requires_confirmation": False}

        return {"intent": "custom", "params": {}, "advice": "",
                "requires_confirmation": False}

    # ── Исполнение ────────────────────────────────────────────────────────────

    def _execute(self, command: str, parsed: Dict) -> CommandResult:
        intent = parsed.get("intent", "custom")
        params = parsed.get("params", {})

        dispatch = {
            "status":        lambda: self._cmd_status(),
            "list_adv":      lambda: self._cmd_list_adv(),
            "set_rate":      lambda: self._cmd_set_rate(params),
            "add_adv":       lambda: self._cmd_add_adv(params),
            "report":        lambda: self._cmd_report(params),
            "test_send":     lambda: self._cmd_test("send"),
            "test_report":   lambda: self._cmd_test("report"),
            "set_threshold": lambda: self._cmd_set_threshold(params),
            "history":       lambda: self._cmd_history(),
            "help":          lambda: self._cmd_help(),
            "custom":        lambda: f"✅ Команда записана: «{command}»",
        }

        try:
            msg      = dispatch.get(intent, dispatch["custom"])()
            executed = True
        except Exception as exc:
            msg      = f"❌ Ошибка: {exc}"
            executed = False
            self.logger.exception("[COMMANDER] Ошибка dispatch intent=%s", intent)

        return CommandResult(command, intent, executed, msg)

    def _execute_pending(self) -> str:
        if not self._pending_confirm:
            return "⚠️ Нет ожидающих команд"
        cmd    = self._pending_confirm["command"]
        parsed = self._pending_confirm["parsed"]
        self._pending_confirm = None
        result = self._execute(cmd, parsed)
        self._history.append(result)
        return result.message

    # ── Команды ───────────────────────────────────────────────────────────────

    def _cmd_status(self) -> str:
        statuses = self.memory.get_all_agent_statuses()
        settings = self._load_settings()
        db_path  = settings.get("db_path", str(ROOT / "data" / "clicks.db"))

        # Клики за последние 24 часа
        since = int(time.time()) - 86400
        try:
            conn = sqlite3.connect(db_path)
            row  = conn.execute(
                "SELECT COUNT(*) FROM clicks WHERE ts >= ? AND is_test = 0", (since,)
            ).fetchone()
            clicks_24h = row[0] if row else 0
            conn.close()
        except Exception:
            clicks_24h = "?"

        emoji_map = {"IDLE": "⚪", "RUNNING": "🟢", "ERROR": "🔴", "STOPPED": "⛔"}
        agent_lines = "\n".join(
            f"  {emoji_map.get(s.split(':')[0], '❓')} {n}: {s}"
            for n, s in statuses.items()
        ) or "  агенты не запущены"

        return (
            f"📊 <b>PreLend — статус</b>\n"
            f"{'─' * 28}\n"
            f"<b>Агенты:</b>\n{agent_lines}\n\n"
            f"<b>Кликов за 24ч:</b> {clicks_24h}"
        )

    def _cmd_list_adv(self) -> str:
        advertisers = self._load_advertisers()
        settings    = self._load_settings()
        db_path     = settings.get("db_path", str(ROOT / "data" / "clicks.db"))

        lines = []
        for adv in advertisers:
            if adv.get("status") != "active":
                continue

            try:
                conn = sqlite3.connect(db_path)
                ls   = conn.execute(
                    "SELECT is_up, uptime_24h FROM landing_status WHERE advertiser_id = ?",
                    (adv["id"],)
                ).fetchone()
                conn.close()
            except Exception:
                ls = None

            flag   = "🟢" if (ls and ls[0]) else "🔴" if ls else "⚪"
            uptime = f"{ls[1]:.1f}%" if ls else "—"
            rate   = adv.get("rate", 0)
            geos   = ", ".join(adv.get("geo", ["ALL"])) or "ALL"

            lines.append(
                f"{flag} <b>{adv['name']}</b> (<code>{adv['id']}</code>)\n"
                f"   Ставка: ${rate} | Uptime: {uptime} | ГЕО: {geos}"
            )

        if not lines:
            return "Нет активных рекламодателей."

        return "📋 <b>Активные рекламодатели:</b>\n\n" + "\n\n".join(lines)

    def _cmd_set_rate(self, params: Dict) -> str:
        adv_id = params.get("adv_id", "")
        rate   = params.get("rate")

        if not adv_id or rate is None:
            return "❌ Укажи: ставка <adv_id> <число>"

        advertisers = self._load_advertisers()
        found       = False

        for adv in advertisers:
            if adv["id"] == adv_id:
                old_rate    = adv["rate"]
                adv["rate"] = float(rate)
                found       = True
                break

        if not found:
            return f"❌ Рекламодатель не найден: {adv_id}"

        self._save_advertisers(advertisers)

        # История ставок в SQLite
        settings = self._load_settings()
        db_path  = settings.get("db_path", str(ROOT / "data" / "clicks.db"))
        try:
            conn = sqlite3.connect(db_path)
            conn.execute(
                "INSERT INTO advertiser_rates (advertiser_id, rate, changed_at, changed_by) VALUES (?,?,?,?)",
                (adv_id, float(rate), int(time.time()), "commander")
            )
            conn.commit()
            conn.close()
        except Exception as exc:
            self.logger.warning("[COMMANDER] Не удалось записать историю ставки: %s", exc)

        self.memory.log_event("COMMANDER", "rate_changed",
                              {"adv_id": adv_id, "old": old_rate, "new": rate})
        return (
            f"✅ Ставка обновлена\n"
            f"Рекламодатель: <b>{adv_id}</b>\n"
            f"Было: ${old_rate} → Стало: <b>${rate}</b>"
        )

    def _cmd_add_adv(self, params: Dict) -> str:
        """Добавляет рекламодателя. params должен содержать готовый dict."""
        required = {"id", "name", "url", "rate", "geo", "subid_param", "template"}
        missing  = required - set(params.keys())
        if missing:
            return f"❌ Не хватает полей: {', '.join(missing)}"

        advertisers = self._load_advertisers()
        # Проверяем уникальность ID
        if any(a["id"] == params["id"] for a in advertisers):
            return f"❌ Рекламодатель с id <code>{params['id']}</code> уже существует"

        new_adv = {
            "id":          params["id"],
            "name":        params["name"],
            "url":         params["url"],
            "rate":        float(params["rate"]),
            "geo":         params["geo"] if isinstance(params["geo"], list) else [params["geo"]],
            "subid_param": params["subid_param"],
            "status":      params.get("status", "active"),
            "device":      params.get("device", []),
            "time_from":   params.get("time_from", ""),
            "time_to":     params.get("time_to", ""),
            "template":    params["template"],
        }
        advertisers.append(new_adv)
        self._save_advertisers(advertisers)

        self.memory.log_event("COMMANDER", "adv_added", {"adv_id": new_adv["id"]})
        return f"✅ Рекламодатель <b>{new_adv['name']}</b> добавлен (<code>{new_adv['id']}</code>)"

    def _cmd_report(self, params: Dict) -> str:
        days = int(params.get("days", 1))
        try:
            import subprocess
            result = subprocess.run(
                ["python3", str(ROOT / "monitor" / "daily_digest.py")],
                capture_output=True, text=True, timeout=30,
            )
            if result.returncode == 0:
                return f"✅ Дайджест за {days} д. отправлен в Telegram."
            return f"❌ Ошибка дайджеста:\n{result.stderr[:300]}"
        except Exception as exc:
            return f"❌ Ошибка: {exc}"

    def _cmd_test(self, mode: str) -> str:
        try:
            import subprocess
            result = subprocess.run(
                ["python3", str(ROOT / "monitor" / "test_conversions.py"), mode],
                capture_output=True, text=True, timeout=30,
            )
            if result.returncode == 0:
                return f"✅ test_conversions {mode} выполнен"
            return f"❌ Ошибка:\n{result.stderr[:300]}"
        except Exception as exc:
            return f"❌ Ошибка: {exc}"

    def _cmd_set_threshold(self, params: Dict) -> str:
        kind  = params.get("type", "")
        value = params.get("value")
        if value is None:
            return "❌ Укажи значение порога"

        settings  = self._load_settings()
        alerts    = settings.setdefault("alerts", {})
        key_map   = {
            "шейва":   "shave_threshold_pct",
            "ботов":   "bot_pct_per_hour",
            "трафика": "offgeo_pct_per_hour",
        }
        key = key_map.get(kind)
        if not key:
            return f"❌ Неизвестный тип порога: {kind}. Доступны: шейва, ботов, трафика"

        old = alerts.get(key, "—")
        alerts[key] = int(value)
        self._save_settings(settings)

        self.memory.log_event("COMMANDER", "threshold_changed",
                              {"key": key, "old": old, "new": value})
        return f"✅ Порог <b>{kind}</b>: {old}% → <b>{value}%</b>"

    def _cmd_history(self) -> str:
        if not self._history:
            return "📋 История команд пуста"
        lines = ["📋 <b>Последние команды:</b>"]
        for r in reversed(self._history[-10:]):
            mark = "✅" if r.executed else "❌"
            lines.append(f"  {mark} [{r.ts}] {r.command}")
        return "\n".join(lines)

    def _cmd_help(self) -> str:
        return (
            "📖 <b>Команды /prelend:</b>\n\n"
            "• <code>статус</code> — состояние агентов и кликов\n"
            "• <code>рекламодатели</code> — список + uptime\n"
            "• <code>ставка &lt;id&gt; &lt;число&gt;</code> — изменить ставку\n"
            "• <code>отчёт [N]</code> — дайджест за N дней\n"
            "• <code>тест отправить</code> — TEST_ конверсии\n"
            "• <code>тест отчёт</code> — отчёт по TEST_\n"
            "• <code>порог шейва &lt;%&gt;</code> — порог алерта шейва\n"
            "• <code>порог ботов &lt;%&gt;</code> — порог алерта ботов\n"
            "• <code>история</code> — последние 10 команд\n\n"
            "Или пиши свободным текстом — Ollama разберёт 🤖"
        )

    # ── Helpers ───────────────────────────────────────────────────────────────

    def _load_advertisers(self) -> List[Dict]:
        with open(CFG_ADV, encoding="utf-8") as f:
            return json.load(f)

    def _save_advertisers(self, data: List[Dict]) -> None:
        with open(CFG_ADV, "w", encoding="utf-8") as f:
            json.dump(data, f, ensure_ascii=False, indent=2)

    def _load_settings(self) -> Dict:
        with open(CFG_SET, encoding="utf-8") as f:
            return json.load(f)

    def _save_settings(self, data: Dict) -> None:
        with open(CFG_SET, "w", encoding="utf-8") as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
