"""
agents/offer_rotator.py — OFFER_ROTATOR PreLend.

Автоматическая ротация офферов при падении CR.

Алгоритм:
  1. Каждый час читает clicks.db: CR = конверсии / клики за каждый из последних N дней
  2. Если CR < порога (cr_threshold_pct) N дней подряд (cr_rotation_days) И включена ротация:
     - Ставит основному офферу status = "paused"
     - Ставит backup-офферу (backup_offer_id) status = "active"
     - Атомарная запись в advertisers.json
     - Telegram-уведомление + запись в ContentHub audit_log
     - Запоминает ротацию в AgentMemory (время ротации + adv_id + backup_id)
  3. Каждый час после ротации: если CR восстановился (>= порога) — автовозврат через 24ч

Поля в advertisers.json (новые):
  backup_offer_id       — ID резервного рекламодателя (str или null)
  cr_threshold_pct      — порог CR в % (default 3.0)
  cr_rotation_days      — дней подряд ниже порога для срабатывания (default 3)
  cr_rotation_enabled   — включить/выключить авторотацию (default false)
  _rotated_at           — ISO timestamp последней ротации (служебное, пишется агентом)
  _original_status      — статус до ротации (служебное)
"""
from __future__ import annotations

import json
import logging
import os
import sqlite3
import tempfile
import time
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from agents.base_agent import BaseAgent, AgentStatus
from agents.memory import AgentMemory, get_memory

logger = logging.getLogger(__name__)

ROOT        = Path(__file__).resolve().parent.parent
CFG_ADV     = ROOT / "config" / "advertisers.json"
CFG_SET     = ROOT / "config" / "settings.json"
AUDIT_LOG   = ROOT / "data" / "offer_rotator_audit.json"  # локальный лог до ContentHub

_CHECK_INTERVAL       = 3600   # секунд между проверками
_RECOVERY_DELAY_HOURS = 24     # часов до автовозврата после восстановления CR


# ── Вспомогательные функции ────────────────────────────────────────────────

def _load_json(path: Path) -> Any:
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def _atomic_write_json(path: Path, data: Any) -> None:
    """Атомарная запись JSON: write-temp → os.replace()."""
    text = json.dumps(data, ensure_ascii=False, indent=2)
    fd, tmp = tempfile.mkstemp(dir=path.parent, suffix=".tmp")
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


def _db_path_from_settings() -> str:
    try:
        s = _load_json(CFG_SET)
        return s.get("db_path", str(ROOT / "data" / "clicks.db"))
    except (OSError, json.JSONDecodeError):
        return str(ROOT / "data" / "clicks.db")


def _get_cr_per_day(
    conn: sqlite3.Connection,
    adv_id: str,
    days: int,
) -> List[Tuple[str, float]]:
    """
    Возвращает список (date_str, cr_pct) за последние `days` дней.
    CR = conversions / clicks * 100. Если кликов 0 — CR = 0.
    """
    results = []
    today = date.today()
    for i in range(days - 1, -1, -1):
        d = (today - timedelta(days=i)).isoformat()

        clicks_row = conn.execute(
            "SELECT COUNT(*) FROM clicks WHERE advertiser_id = ? AND date(datetime(ts,'unixepoch')) = ? AND is_test = 0",
            (adv_id, d),
        ).fetchone()
        clicks = clicks_row[0] if clicks_row else 0

        convs_row = conn.execute(
            "SELECT COUNT(*) FROM conversions WHERE advertiser_id = ? AND date = ? AND source = 'api'",
            (adv_id, d),
        ).fetchone()
        convs = convs_row[0] if convs_row else 0

        cr = (convs / clicks * 100) if clicks > 0 else 0.0
        results.append((d, cr))

    return results


# ── Агент ─────────────────────────────────────────────────────────────────


class OfferRotator(BaseAgent):

    def __init__(
        self,
        memory: Optional[AgentMemory] = None,
        notify: Any = None,
    ) -> None:
        super().__init__("OFFER_ROTATOR", memory, notify)

    # ── run() — фоновый цикл ──────────────────────────────────────────────

    def run(self) -> None:
        self.logger.info("[OFFER_ROTATOR] Запущен")
        while not self.should_stop:
            self._set_status(AgentStatus.RUNNING, "проверка CR")
            try:
                self.check_all()
            except Exception as exc:
                self.logger.error("[OFFER_ROTATOR] Ошибка цикла: %s", exc, exc_info=True)
            finally:
                self._set_status(AgentStatus.IDLE)

            if not self.sleep(_CHECK_INTERVAL):
                break

    # ── Основная проверка (публичная) ────────────────────────────────────

    def check_all(self) -> None:
        """Проверяет всех рекламодателей с включённой авторотацией."""
        try:
            advertisers = _load_json(CFG_ADV)
        except (OSError, json.JSONDecodeError) as exc:
            self.logger.error("[OFFER_ROTATOR] Не удалось прочитать advertisers.json: %s", exc)
            return

        db_path = _db_path_from_settings()
        changed = False

        try:
            conn = sqlite3.connect(db_path, timeout=5.0)
        except sqlite3.Error as exc:
            self.logger.error("[OFFER_ROTATOR] Не удалось открыть clicks.db: %s", exc)
            return

        try:
            with conn:
                for adv in advertisers:
                    if not adv.get("cr_rotation_enabled", False):
                        continue

                    was_changed = self._process_advertiser(adv, advertisers, conn)
                    if was_changed:
                        changed = True
        finally:
            conn.close()

        if changed:
            _atomic_write_json(CFG_ADV, advertisers)
            self.logger.info("[OFFER_ROTATOR] advertisers.json обновлён")

    # ── Логика для одного рекламодателя ──────────────────────────────────

    def _process_advertiser(
        self,
        adv: Dict,
        all_advs: List[Dict],
        conn: sqlite3.Connection,
    ) -> bool:
        """
        Возвращает True если данные были изменены (нужна запись в файл).
        Мутирует adv и backup напрямую — список all_advs обновится через них.
        """
        adv_id      = adv["id"]
        threshold   = float(adv.get("cr_threshold_pct", 3.0))
        rot_days    = int(adv.get("cr_rotation_days", 3))
        backup_id   = adv.get("backup_offer_id")
        is_rotated  = bool(adv.get("_rotated_at"))

        cr_history = _get_cr_per_day(conn, adv_id, rot_days)
        self.logger.debug(
            "[OFFER_ROTATOR] %s CR история: %s (порог=%.1f%%)",
            adv_id, cr_history, threshold,
        )

        # ── Уже в ротации → проверяем восстановление ─────────────────────
        if is_rotated:
            return self._check_recovery(adv, all_advs, cr_history, threshold)

        # ── Не в ротации → проверяем нужно ли ротировать ─────────────────
        if adv.get("status") != "active":
            return False  # уже paused вручную — не трогаем

        if not backup_id:
            self.logger.debug("[OFFER_ROTATOR] %s — нет backup_offer_id, пропускаем", adv_id)
            return False

        # Все дни ниже порога?
        all_low = all(cr < threshold for _, cr in cr_history)
        if not all_low:
            return False

        # Найти backup-рекламодателя
        backup = self._find_adv(all_advs, backup_id)
        if backup is None:
            self.logger.warning("[OFFER_ROTATOR] backup_offer_id=%s не найден", backup_id)
            return False

        # Ротация!
        avg_cr = sum(cr for _, cr in cr_history) / len(cr_history) if cr_history else 0
        adv["_original_status"] = adv.get("status", "active")
        adv["status"]           = "paused"
        adv["_rotated_at"]      = datetime.utcnow().isoformat()

        backup["_original_status"] = backup.get("status", "paused")
        backup["status"]           = "active"

        msg = (
            f"🔄 [OfferRotator] Ротация: {adv_id} → paused\n"
            f"Причина: CR={avg_cr:.2f}% < {threshold}% за {rot_days} дней подряд\n"
            f"Резервный: {backup_id} → active"
        )
        self.logger.info(msg)
        self._send(msg)
        self._append_audit({
            "ts": datetime.utcnow().isoformat(),
            "action": "rotate",
            "adv_id": adv_id,
            "backup_id": backup_id,
            "avg_cr": avg_cr,
            "threshold": threshold,
            "days": rot_days,
        })

        return True

    def _check_recovery(
        self,
        adv: Dict,
        all_advs: List[Dict],
        cr_history: List[Tuple[str, float]],
        threshold: float,
    ) -> bool:
        """Если CR восстановился → откатить ротацию через _RECOVERY_DELAY_HOURS."""
        rotated_at_str = adv.get("_rotated_at", "")
        try:
            rotated_at = datetime.fromisoformat(rotated_at_str)
        except (ValueError, TypeError):
            return False

        hours_since = (datetime.utcnow() - rotated_at).total_seconds() / 3600
        if hours_since < _RECOVERY_DELAY_HOURS:
            return False  # ещё не прошло 24 часа

        # Проверяем CR — восстановился?
        avg_cr = sum(cr for _, cr in cr_history) / len(cr_history) if cr_history else 0
        if avg_cr < threshold:
            return False  # всё ещё плохой

        # Откат!
        backup_id = adv.get("backup_offer_id")
        backup    = self._find_adv(all_advs, backup_id) if backup_id else None

        adv["status"]    = adv.pop("_original_status", "active")
        adv["_rotated_at"] = None  # сброс маркера

        if backup:
            backup["status"] = backup.pop("_original_status", "paused")

        msg = (
            f"✅ [OfferRotator] Автовозврат: {adv['id']} → {adv['status']}\n"
            f"CR восстановился: {avg_cr:.2f}% >= {threshold}% (ждали {hours_since:.1f}ч)"
        )
        self.logger.info(msg)
        self._send(msg)
        self._append_audit({
            "ts": datetime.utcnow().isoformat(),
            "action": "recover",
            "adv_id": adv["id"],
            "backup_id": backup_id,
            "avg_cr": avg_cr,
            "threshold": threshold,
            "hours_since_rotation": round(hours_since, 1),
        })

        return True

    # ── Утилиты ──────────────────────────────────────────────────────────

    @staticmethod
    def _find_adv(advs: List[Dict], adv_id: str) -> Optional[Dict]:
        for a in advs:
            if a["id"] == adv_id:
                return a
        return None

    def _append_audit(self, entry: Dict) -> None:
        """Дописывает запись в локальный audit-лог (до ContentHub)."""
        try:
            AUDIT_LOG.parent.mkdir(parents=True, exist_ok=True)
            if AUDIT_LOG.exists():
                existing = _load_json(AUDIT_LOG)
            else:
                existing = []
            existing.append(entry)
            # Храним максимум 500 записей
            if len(existing) > 500:
                existing = existing[-500:]
            _atomic_write_json(AUDIT_LOG, existing)
        except Exception as exc:
            self.logger.warning("[OFFER_ROTATOR] Ошибка записи audit: %s", exc)
