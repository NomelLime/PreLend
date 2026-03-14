"""
agents/analyst.py — ANALYST PreLend.

Антишейв-анализ через Ollama. Запускается:
  - по cron (через shave_detector.py)
  - по команде /prelend analyst из COMMANDER
  - по триггеру от MONITOR при аномалиях

Алгоритм:
  1. Читает data/shave_report.json (заполняется shave_detector.py)
  2. Читает из SQLite паттерны кликов + конверсий:
       - разбивку по времени суток
       - разбивку по ГЕО
       - разбивку по платформам
  3. Передаёт данные в Ollama с аналитическим промптом
  4. Выносит вердикт: shave / bad_traffic / random / ok
  5. Отправляет Telegram-отчёт
  6. Сохраняет вердикт в AgentMemory для COMMANDER

Если Ollama недоступна — выдаёт статистический отчёт без LLM.
"""
from __future__ import annotations

import json
import logging
import sqlite3
import time
from datetime import date, timedelta
from pathlib import Path
from typing import Any, Dict, List, Optional

from agents.base_agent import BaseAgent, AgentStatus
from agents.memory import AgentMemory, get_memory

logger = logging.getLogger(__name__)

ROOT              = Path(__file__).resolve().parent.parent
SHAVE_REPORT_FILE = ROOT / "data" / "shave_report.json"
CFG_SET           = ROOT / "config" / "settings.json"

_CHECK_INTERVAL = 3600  # раз в час в фоновом режиме
_ANALYSIS_DAYS  = 7

# ── Ollama prompt ──────────────────────────────────────────────────────────────
_ANALYST_PROMPT = """Ты — аналитик антишейв-системы в affiliate маркетинге.
Тебе предоставлены данные о расхождениях CR рекламодателей.
Ответь ТОЛЬКО валидным JSON без пояснений и markdown.

Данные по рекламодателям (за последние {days} дней):
{data}

Для каждого рекламодателя с подозрением на шейв вынеси вердикт:
  shave       — явное занижение CR, признаки умышленного шейва
  bad_traffic — CR низкий у всех, проблема в качестве трафика
  random      — статистическая случайность, недостаточно данных
  ok          — всё в норме

Формат ответа:
{{
  "verdicts": {{
    "adv_id": {{
      "verdict": "shave|bad_traffic|random|ok",
      "confidence": "high|medium|low",
      "reason": "1-2 предложения на русском"
    }}
  }},
  "summary": "общий вывод по всем рекламодателям (2-3 предложения на русском)"
}}"""


class Analyst(BaseAgent):

    def __init__(
        self,
        memory: Optional[AgentMemory] = None,
        notify: Any = None,
    ) -> None:
        super().__init__("ANALYST", memory, notify)

    def enqueue_analysis(self) -> None:
        """Триггерный метод — вызывается из MONITOR при аномалиях."""
        self.memory.set("analyst_triggered", True, persist=False)

    # ── run() — фоновый цикл ──────────────────────────────────────────────────

    def run(self) -> None:
        self.logger.info("[ANALYST] Запущен")
        while not self.should_stop:
            self._set_status(AgentStatus.RUNNING, "анализ")
            try:
                self.analyze()
            except Exception as exc:
                self.logger.error("[ANALYST] Ошибка цикла: %s", exc)
            finally:
                self._set_status(AgentStatus.IDLE)

            if not self.sleep(_CHECK_INTERVAL):
                break

    # ── Основной анализ (публичный — вызывается и вручную) ───────────────────

    def analyze(self, force_report: bool = False) -> str:
        """
        Запускает полный анализ. Возвращает текст отчёта.
        force_report=True — отправить в Telegram даже если нет подозрений.
        """
        self.logger.info("[ANALYST] Начало анализа")

        shave_data = self._load_shave_report()
        if not shave_data:
            msg = "⚠️ [ANALYST] Нет данных shave_report.json. Запусти shave_detector.py."
            self.logger.warning(msg)
            return msg

        # Обогащаем данные паттернами из SQLite
        enriched = self._enrich_with_patterns(shave_data)

        # Подозреваемые
        suspects = {
            adv_id: info
            for adv_id, info in enriched.items()
            if info.get("verdict") == "shave_suspected"
        }

        if not suspects and not force_report:
            self.logger.info("[ANALYST] Подозрений нет — пропускаем отчёт")
            return "✅ Подозрений на шейв нет"

        # Анализ через Ollama
        analysis = self._analyze_with_ollama(enriched)

        # Формируем отчёт
        report_text = self._build_report(enriched, analysis)

        # Сохраняем вердикт в памяти
        self.memory.set("analyst_last_verdicts", {
            "ts":       date.today().isoformat(),
            "verdicts": analysis.get("verdicts", {}),
            "summary":  analysis.get("summary", ""),
        })
        self.report({"analysis": analysis, "suspects_count": len(suspects)})

        # Отправляем в Telegram только если есть подозрения или force
        if suspects or force_report:
            self._send(report_text)

        return report_text

    # ── Загрузка данных ───────────────────────────────────────────────────────

    def _load_shave_report(self) -> Optional[Dict]:
        if not SHAVE_REPORT_FILE.exists():
            return None
        try:
            with open(SHAVE_REPORT_FILE, encoding="utf-8") as f:
                data = json.load(f)
            return data.get("report", {})
        except Exception as exc:
            self.logger.warning("[ANALYST] Ошибка чтения shave_report: %s", exc)
            return None

    def _enrich_with_patterns(self, report: Dict) -> Dict:
        """Добавляет паттерны кликов по времени/ГЕО/платформе из SQLite."""
        settings = self._load_settings()
        db_path  = settings.get("db_path", str(ROOT / "data" / "clicks.db"))
        since    = int(time.time()) - _ANALYSIS_DAYS * 86400

        enriched = dict(report)  # копируем

        try:
            conn = sqlite3.connect(db_path, timeout=5.0)
            conn.row_factory = sqlite3.Row

            for adv_id in enriched:
                # Паттерн по часам (UTC)
                rows = conn.execute("""
                    SELECT strftime('%H', datetime(ts, 'unixepoch')) AS hour,
                           COUNT(*) AS clicks
                    FROM clicks
                    WHERE advertiser_id = ? AND ts >= ? AND status = 'sent' AND is_test = 0
                    GROUP BY hour ORDER BY hour
                """, (adv_id, since)).fetchall()
                enriched[adv_id]["clicks_by_hour"] = {
                    r["hour"]: r["clicks"] for r in rows
                }

                # Паттерн по ГЕО
                rows = conn.execute("""
                    SELECT geo, COUNT(*) AS clicks
                    FROM clicks
                    WHERE advertiser_id = ? AND ts >= ? AND status = 'sent' AND is_test = 0
                    GROUP BY geo ORDER BY clicks DESC LIMIT 10
                """, (adv_id, since)).fetchall()
                enriched[adv_id]["clicks_by_geo"] = {
                    r["geo"]: r["clicks"] for r in rows
                }

                # Паттерн по платформе
                rows = conn.execute("""
                    SELECT platform, COUNT(*) AS clicks
                    FROM clicks
                    WHERE advertiser_id = ? AND ts >= ? AND status = 'sent' AND is_test = 0
                    GROUP BY platform ORDER BY clicks DESC
                """, (adv_id, since)).fetchall()
                enriched[adv_id]["clicks_by_platform"] = {
                    r["platform"]: r["clicks"] for r in rows
                }

            conn.close()
        except Exception as exc:
            self.logger.warning("[ANALYST] Ошибка обогащения данных: %s", exc)

        return enriched

    # ── Ollama анализ ─────────────────────────────────────────────────────────

    def _analyze_with_ollama(self, enriched: Dict) -> Dict:
        """Отправляет данные в Ollama, возвращает вердикты."""
        if self.memory.get("ollama_available") is False:
            return self._statistical_analysis(enriched)

        try:
            import ollama  # type: ignore
            settings = self._load_settings()
            model    = settings.get("ollama", {}).get("model", "qwen2.5-vl:7b")

            # Сжимаем данные для промпта (не засоряем контекст)
            slim_data = {
                adv_id: {
                    "name":     info.get("name"),
                    "clicks":   info.get("clicks"),
                    "convs":    info.get("convs"),
                    "cr":       round(info["cr"], 4) if info.get("cr") else None,
                    "shave_coef": info.get("shave_coef"),
                    "verdict":  info.get("verdict"),
                    "top_geo":  list(info.get("clicks_by_geo", {}).items())[:5],
                    "top_hours": sorted(
                        info.get("clicks_by_hour", {}).items(),
                        key=lambda x: -x[1]
                    )[:5],
                }
                for adv_id, info in enriched.items()
            }

            prompt   = _ANALYST_PROMPT.format(
                days=_ANALYSIS_DAYS,
                data=json.dumps(slim_data, ensure_ascii=False, indent=2)
            )
            response = ollama.generate(
                model=model,
                prompt=prompt,
                options={"temperature": 0.2},
            )
            raw = response.get("response", "{}").strip()
            raw = raw.lstrip("```json").lstrip("```").rstrip("```").strip()

            result = json.loads(raw)

            # Минимальная schema validation — защита от malformed LLM-ответа
            if not isinstance(result, dict):
                raise ValueError("Ollama вернул не dict")
            verdicts = result.get("verdicts")
            if verdicts is not None and not isinstance(verdicts, dict):
                raise ValueError("'verdicts' должен быть dict")
            # Проверяем структуру каждого вердикта
            for adv_id, v in (verdicts or {}).items():
                if not isinstance(v, dict):
                    raise ValueError(f"Вердикт для {adv_id} не является dict")
                if "verdict" not in v:
                    raise ValueError(f"Нет поля 'verdict' для {adv_id}")

            self.logger.info("[ANALYST] Ollama вернул вердикты: %s",
                             list((verdicts or {}).keys()))
            return result

        except ImportError:
            self.logger.debug("[ANALYST] ollama не установлен — статистический анализ")
        except json.JSONDecodeError as exc:
            self.logger.warning("[ANALYST] Ollama вернул не-JSON: %s", exc)
        except Exception as exc:
            self.logger.warning("[ANALYST] Ollama недоступна: %s — статистический анализ", exc)
            self.memory.set("ollama_available", False)

        return self._statistical_analysis(enriched)

    def _statistical_analysis(self, enriched: Dict) -> Dict:
        """Вердикт без LLM — по числовым порогам."""
        verdicts = {}
        for adv_id, info in enriched.items():
            shave = info.get("shave_coef")
            cr    = info.get("cr")

            if shave is None or cr is None:
                v, conf, reason = "random", "low", "Недостаточно данных для анализа"
            elif shave >= 0.40:
                v, conf, reason = "shave", "high", \
                    f"CR рекламодателя на {shave:.0%} ниже медианы — высокая вероятность шейва"
            elif shave >= 0.20:
                v, conf, reason = "shave", "medium", \
                    f"CR на {shave:.0%} ниже медианы — возможный шейв, требует наблюдения"
            elif cr < 0.005:
                v, conf, reason = "bad_traffic", "medium", \
                    "Очень низкий CR у всех — вероятно низкое качество трафика"
            else:
                v, conf, reason = "ok", "high", "Показатели в норме"

            verdicts[adv_id] = {"verdict": v, "confidence": conf, "reason": reason}

        summary_shave = sum(1 for v in verdicts.values() if v["verdict"] == "shave")
        summary = (
            f"Статистический анализ (Ollama недоступна). "
            f"Подозрений на шейв: {summary_shave} из {len(verdicts)} рекламодателей."
        )
        return {"verdicts": verdicts, "summary": summary}

    # ── Формирование отчёта ───────────────────────────────────────────────────

    def _build_report(self, enriched: Dict, analysis: Dict) -> str:
        verdicts = analysis.get("verdicts", {})
        summary  = analysis.get("summary", "")

        verdict_icon = {
            "shave":       "🚨",
            "bad_traffic": "🟠",
            "random":      "🟡",
            "ok":          "✅",
        }
        conf_label = {"high": "высокая", "medium": "средняя", "low": "низкая"}

        lines = ["🔍 <b>ANALYST — Антишейв отчёт</b>", f"Период: {_ANALYSIS_DAYS} дней\n"]

        for adv_id, info in enriched.items():
            v_data = verdicts.get(adv_id, {})
            verdict = v_data.get("verdict", "?")
            conf    = conf_label.get(v_data.get("confidence", ""), "?")
            reason  = v_data.get("reason", "")
            cr      = info.get("cr")
            shave   = info.get("shave_coef")
            icon    = verdict_icon.get(verdict, "❓")

            cr_str    = f"{cr:.2%}" if cr is not None else "н/д"
            shave_str = f"{shave:.1%}" if shave is not None else "н/д"

            lines.append(
                f"{icon} <b>{info.get('name', adv_id)}</b>\n"
                f"   CR: {cr_str} | Shave-коэф: {shave_str}\n"
                f"   Вердикт: <b>{verdict}</b> (уверенность: {conf})\n"
                f"   {reason}"
            )

        if summary:
            lines.append(f"\n📝 <b>Вывод:</b>\n{summary}")

        return "\n\n".join(lines)

    # ── Helpers ───────────────────────────────────────────────────────────────

    def _load_settings(self) -> Dict:
        with open(CFG_SET, encoding="utf-8") as f:
            return json.load(f)
