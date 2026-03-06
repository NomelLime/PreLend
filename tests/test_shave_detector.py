"""
tests/test_shave_detector.py — Тесты shave_detector и ANALYST-пайплайна.

Покрываем:
  median()                     — граничные случаи
  calculate_shave()            — расчёт коэффициентов, пороги
  detect_conversion_patterns() — флаги no_api / dropoff / low_api_ratio
  collect_cr_data()            — чтение из SQLite с реальными данными
  run() —no Telegram           — сквозной тест без отправки уведомлений
"""
from __future__ import annotations

import json
import os
import sqlite3
import sys
import time
import unittest
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from monitor.shave_detector import (
    median,
    collect_cr_data,
    calculate_shave,
    detect_conversion_patterns,
    ANALYSIS_DAYS,
    MIN_CLICKS,
)


# ── Вспомогательные функции ───────────────────────────────────────────────────

def make_db() -> sqlite3.Connection:
    """In-memory SQLite с полной схемой PreLend."""
    conn = sqlite3.connect(":memory:")
    conn.row_factory = sqlite3.Row
    sql = (ROOT / "data" / "init_db.sql").read_text()
    conn.executescript(sql)
    return conn


def seed_clicks(conn: sqlite3.Connection, adv_id: str, count: int,
                status: str = "sent", geo: str = "UA") -> None:
    ts = int(time.time()) - 3600
    for i in range(count):
        conn.execute("""
            INSERT INTO clicks
              (click_id, ts, ip, geo, device, platform, advertiser_id, status, is_test, ua_hash)
            VALUES (?,?,?,?,?,?,?,?,0,?)
        """, (f"{adv_id}-click-{i}", ts, "1.2.3.4", geo,
              "mobile", "youtube", adv_id, status, "abc"))
    conn.commit()


def seed_conversions(conn: sqlite3.Connection, adv_id: str, count: int,
                     source: str = "api") -> None:
    import uuid
    ts = int(time.time()) - 3600
    for i in range(count):
        conn.execute("""
            INSERT INTO conversions (conv_id, date, advertiser_id, count, source, notes, created_at)
            VALUES (?,?,?,1,?,?,?)
        """, (str(uuid.uuid4()), date.today().isoformat(), adv_id, source, "", ts))
    conn.commit()


# ══════════════════════════════════════════════════════════════════════════════
# median()
# ══════════════════════════════════════════════════════════════════════════════

class TestMedian(unittest.TestCase):

    def test_empty(self):
        self.assertEqual(0.0, median([]))

    def test_single(self):
        self.assertEqual(5.0, median([5.0]))

    def test_odd(self):
        self.assertEqual(3.0, median([1.0, 3.0, 5.0]))

    def test_even(self):
        self.assertEqual(2.5, median([1.0, 2.0, 3.0, 4.0]))

    def test_unsorted(self):
        self.assertEqual(3.0, median([5.0, 1.0, 3.0]))

    def test_all_same(self):
        self.assertEqual(4.0, median([4.0, 4.0, 4.0]))


# ══════════════════════════════════════════════════════════════════════════════
# calculate_shave()
# ══════════════════════════════════════════════════════════════════════════════

class TestCalculateShave(unittest.TestCase):

    def _make_report(self, cr_map: dict, geo: str = "UA") -> tuple:
        """Строит report и cr_by_geo из словаря adv_id → cr."""
        report = {}
        cr_by_geo = {}
        for adv_id, cr in cr_map.items():
            report[adv_id] = {
                "name": adv_id, "clicks": 100, "convs": int(cr * 100),
                "cr": cr if cr is not None else None,
                "geo_key": geo,
                "conv_by_src": {}, "conv_by_day": {},
            }
            if cr is not None:
                cr_by_geo.setdefault(geo, []).append(cr)
        return report, cr_by_geo

    def test_ok_no_shave(self):
        report, cr_by_geo = self._make_report({"a": 0.05, "b": 0.05, "c": 0.05})
        report, suspects = calculate_shave(report, cr_by_geo, threshold=0.25)
        self.assertEqual(0, len(suspects))
        for v in report.values():
            self.assertEqual("ok", v["verdict"])

    def test_shave_detected(self):
        # a = 0.01, median(0.01, 0.05, 0.05) = 0.05
        # shave_coef = (0.05 - 0.01) / 0.05 = 0.80 > 0.25
        report, cr_by_geo = self._make_report({"a": 0.01, "b": 0.05, "c": 0.05})
        report, suspects = calculate_shave(report, cr_by_geo, threshold=0.25)
        suspect_ids = [s[0] for s in suspects]
        self.assertIn("a", suspect_ids)
        self.assertEqual("shave_suspected", report["a"]["verdict"])

    def test_insufficient_data_no_cr(self):
        report = {"x": {"name": "x", "clicks": 5, "convs": 0, "cr": None,
                        "geo_key": "UA", "conv_by_src": {}, "conv_by_day": {}}}
        cr_by_geo = {}
        report, suspects = calculate_shave(report, cr_by_geo, threshold=0.25)
        self.assertEqual("insufficient_data", report["x"]["verdict"])
        self.assertEqual(0, len(suspects))

    def test_no_peers_single_advertiser(self):
        report, cr_by_geo = self._make_report({"solo": 0.03})
        report, suspects = calculate_shave(report, cr_by_geo, threshold=0.25)
        self.assertEqual("no_peers", report["solo"]["verdict"])
        self.assertEqual(0, len(suspects))

    def test_shave_coef_capped_at_zero(self):
        # a выше медианы — коэффициент не уходит в минус
        report, cr_by_geo = self._make_report({"a": 0.10, "b": 0.03, "c": 0.03})
        report, _ = calculate_shave(report, cr_by_geo, threshold=0.25)
        self.assertEqual("ok", report["a"]["verdict"])
        self.assertGreaterEqual(report["a"]["shave_coef"], 0.0)

    def test_exact_threshold_boundary(self):
        # shave_coef ровно на пороге: должен быть shave_suspected
        # median([0.04, 0.04, x]) = 0.04 → shave = (0.04 - x) / 0.04 = 0.25 → x = 0.03
        report, cr_by_geo = self._make_report({"a": 0.03, "b": 0.04, "c": 0.04})
        report, suspects = calculate_shave(report, cr_by_geo, threshold=0.25)
        self.assertIn("a", [s[0] for s in suspects])


# ══════════════════════════════════════════════════════════════════════════════
# detect_conversion_patterns()
# ══════════════════════════════════════════════════════════════════════════════

class TestPatterns(unittest.TestCase):

    def _make_report(self, conv_by_src: dict, conv_by_day: dict,
                     total: int) -> dict:
        return {"adv": {
            "name": "Test", "clicks": 200, "convs": total, "cr": total / 200,
            "geo_key": "UA", "conv_by_src": conv_by_src, "conv_by_day": conv_by_day,
        }}

    def test_no_flags_normal(self):
        today = date.today().isoformat()
        report = self._make_report({"api": 10, "manual": 2}, {today: 12}, 12)
        patterns = detect_conversion_patterns(report)
        self.assertEqual([], patterns["adv"])

    def test_no_api_conversions(self):
        report = self._make_report({"manual": 5}, {}, 5)
        patterns = detect_conversion_patterns(report)
        self.assertIn("no_api_conversions", patterns["adv"])

    def test_conversion_dropoff(self):
        # Конверсий 10 всего, но за последние 3 дня — ноль
        old_days = {
            (date.today() - timedelta(days=i)).isoformat(): 2
            for i in range(4, 9)
        }
        report = self._make_report({"api": 10}, old_days, 10)
        patterns = detect_conversion_patterns(report)
        self.assertIn("conversion_dropoff", patterns["adv"])

    def test_low_api_ratio(self):
        # 1 api из 20 total = 5% < 10% порога
        report = self._make_report({"api": 1, "manual": 19}, {}, 20)
        patterns = detect_conversion_patterns(report)
        self.assertIn("low_api_ratio", patterns["adv"])

    def test_no_flags_if_too_few_conversions(self):
        # Меньше 10 конверсий — low_api_ratio не срабатывает
        report = self._make_report({"manual": 3}, {}, 3)
        patterns = detect_conversion_patterns(report)
        self.assertNotIn("low_api_ratio", patterns["adv"])


# ══════════════════════════════════════════════════════════════════════════════
# collect_cr_data() — интеграция с SQLite
# ══════════════════════════════════════════════════════════════════════════════

class TestCollectCR(unittest.TestCase):

    def test_no_clicks_cr_none(self):
        conn = make_db()
        adv_map = {"adv_001": {"name": "Test", "geo": ["UA"]}}
        ts_since = int(time.time()) - ANALYSIS_DAYS * 86400
        report, cr_by_geo = collect_cr_data(conn, adv_map, ts_since)
        conn.close()
        self.assertIsNone(report["adv_001"]["cr"])
        self.assertEqual(0, report["adv_001"]["clicks"])

    def test_clicks_below_min_cr_none(self):
        conn = make_db()
        seed_clicks(conn, "adv_001", MIN_CLICKS - 1)
        adv_map = {"adv_001": {"name": "Test", "geo": ["UA"]}}
        ts_since = int(time.time()) - ANALYSIS_DAYS * 86400
        report, _ = collect_cr_data(conn, adv_map, ts_since)
        conn.close()
        self.assertIsNone(report["adv_001"]["cr"])

    def test_cr_calculated_correctly(self):
        conn = make_db()
        seed_clicks(conn, "adv_001", 100)
        seed_conversions(conn, "adv_001", 5, source="api")
        adv_map = {"adv_001": {"name": "Test", "geo": ["UA"]}}
        ts_since = int(time.time()) - ANALYSIS_DAYS * 86400
        report, cr_by_geo = collect_cr_data(conn, adv_map, ts_since)
        conn.close()
        self.assertAlmostEqual(0.05, report["adv_001"]["cr"], places=4)
        self.assertEqual(5, report["adv_001"]["convs"])
        self.assertIn("UA", report["adv_001"]["geo_key"])
        # cr_by_geo заполнен
        self.assertIn("UA", cr_by_geo)

    def test_conv_by_src_breakdown(self):
        conn = make_db()
        seed_clicks(conn, "adv_001", 50)
        seed_conversions(conn, "adv_001", 3, source="api")
        seed_conversions(conn, "adv_001", 2, source="manual")
        adv_map = {"adv_001": {"name": "Test", "geo": ["UA"]}}
        ts_since = int(time.time()) - ANALYSIS_DAYS * 86400
        report, _ = collect_cr_data(conn, adv_map, ts_since)
        conn.close()
        src = report["adv_001"]["conv_by_src"]
        self.assertEqual(3, src.get("api", 0))
        self.assertEqual(2, src.get("manual", 0))

    def test_is_test_clicks_excluded(self):
        conn = make_db()
        seed_clicks(conn, "adv_001", 100, status="sent")
        # is_test клики — не должны входить в CR
        for i in range(20):
            conn.execute("""
                INSERT INTO clicks (click_id,ts,ip,geo,device,platform,advertiser_id,status,is_test,ua_hash)
                VALUES (?,?,?,?,?,?,?,?,1,?)
            """, (f"test-{i}", int(time.time()), "1.2.3.4", "UA",
                  "mobile", "youtube", "adv_001", "sent", "abc"))
        conn.commit()
        seed_conversions(conn, "adv_001", 5)
        adv_map = {"adv_001": {"name": "Test", "geo": ["UA"]}}
        ts_since = int(time.time()) - ANALYSIS_DAYS * 86400
        report, _ = collect_cr_data(conn, adv_map, ts_since)
        conn.close()
        # clicks = 100 (не 120)
        self.assertEqual(100, report["adv_001"]["clicks"])


# ══════════════════════════════════════════════════════════════════════════════
# Сквозной тест run() без Telegram
# ══════════════════════════════════════════════════════════════════════════════

class TestRunIntegration(unittest.TestCase):

    def setUp(self):
        """Создаём временный settings.json с in-memory путём."""
        import tempfile, shutil
        self.tmp_dir = Path(tempfile.mkdtemp())
        self.db_path = self.tmp_dir / "clicks.db"

        # Инициализируем реальную БД
        conn = sqlite3.connect(str(self.db_path))
        conn.executescript((ROOT / "data" / "init_db.sql").read_text())

        # 2 рекламодателя, 100 кликов каждый, разные CR
        for i in range(100):
            conn.execute("""
                INSERT INTO clicks (click_id,ts,ip,geo,device,platform,advertiser_id,status,is_test,ua_hash)
                VALUES (?,?,?,?,?,?,?,?,0,?)
            """, (f"a1-{i}", int(time.time()), "1.2.3.4", "UA",
                  "mobile", "youtube", "adv_001", "sent", "abc"))
        for i in range(100):
            conn.execute("""
                INSERT INTO clicks (click_id,ts,ip,geo,device,platform,advertiser_id,status,is_test,ua_hash)
                VALUES (?,?,?,?,?,?,?,?,0,?)
            """, (f"a2-{i}", int(time.time()), "1.2.3.4", "UA",
                  "mobile", "youtube", "adv_002", "sent", "def"))

        # adv_001: CR = 5%, adv_002: CR = 1% (будет shave_suspected)
        for i in range(5):
            conn.execute("""
                INSERT INTO conversions (conv_id,date,advertiser_id,count,source,notes,created_at)
                VALUES (?,?,?,1,'api','',?)
            """, (f"c1-{i}", date.today().isoformat(), "adv_001", int(time.time())))
        for i in range(1):
            conn.execute("""
                INSERT INTO conversions (conv_id,date,advertiser_id,count,source,notes,created_at)
                VALUES (?,?,?,1,'api','',?)
            """, (f"c2-{i}", date.today().isoformat(), "adv_002", int(time.time())))
        conn.commit()
        conn.close()

        # Временные конфиги
        self.cfg_dir = self.tmp_dir / "config"
        self.cfg_dir.mkdir()
        settings = json.loads((ROOT / "config" / "settings.json").read_text())
        settings["db_path"] = str(self.db_path)
        (self.cfg_dir / "settings.json").write_text(json.dumps(settings))

        adv = [
            {"id": "adv_001", "name": "A1", "url": "https://a1.ex", "rate": 5.0,
             "geo": ["UA"], "status": "active", "subid_param": "s"},
            {"id": "adv_002", "name": "A2", "url": "https://a2.ex", "rate": 5.0,
             "geo": ["UA"], "status": "active", "subid_param": "s"},
        ]
        (self.cfg_dir / "advertisers.json").write_text(json.dumps(adv))

        self.report_file = self.tmp_dir / "data" / "shave_report.json"
        (self.tmp_dir / "data").mkdir()

        # Патчим пути в shave_detector
        import monitor.shave_detector as sd
        self._orig_report = sd.SHAVE_REPORT_FILE
        self._orig_root   = sd.ROOT
        sd.SHAVE_REPORT_FILE = self.report_file

        # Мокаем alert() — не отправляем Telegram
        self._orig_alert = sd.alert
        sd.alert = lambda msg: None

        self._sd = sd
        self._cfg_backup = None

    def tearDown(self):
        import shutil
        self._sd.SHAVE_REPORT_FILE = self._orig_report
        self._sd.alert             = self._orig_alert
        shutil.rmtree(self.tmp_dir, ignore_errors=True)

    def test_run_creates_report(self):
        """run() создаёт shave_report.json с корректной структурой."""
        import monitor.shave_detector as sd

        # Переопределяем load_json чтобы читать из tmp_dir
        orig_load = sd.load_json
        def patched_load(path):
            name = Path(path).name
            return orig_load(self.cfg_dir / name)
        sd.load_json = patched_load

        try:
            sd.run(trigger_analyst=False, force=False)
        finally:
            sd.load_json = orig_load

        self.assertTrue(self.report_file.exists(), "shave_report.json должен быть создан")

        data = json.loads(self.report_file.read_text())
        self.assertIn("report", data)
        self.assertIn("adv_001", data["report"])
        self.assertIn("adv_002", data["report"])

    def test_run_detects_shave(self):
        """adv_002 с CR=1% при медиане 5% должен получить shave_suspected."""
        import monitor.shave_detector as sd

        orig_load = sd.load_json
        def patched_load(path):
            return orig_load(self.cfg_dir / Path(path).name)
        sd.load_json = patched_load

        try:
            sd.run(trigger_analyst=False, force=False)
        finally:
            sd.load_json = orig_load

        data   = json.loads(self.report_file.read_text())
        adv2   = data["report"]["adv_002"]
        self.assertEqual("shave_suspected", adv2["verdict"])
        self.assertGreater(adv2["shave_coef"], 0.25)


if __name__ == "__main__":
    unittest.main(verbosity=2)
