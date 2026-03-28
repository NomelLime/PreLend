"""
Фикстуры: изолированное дерево PreLend + патчи internal_api.config.

Запуск из корня репозитория PreLend:
    python -m pytest internal_api/tests -v

Зависимости: pip install -r internal_api/requirements.txt pytest
"""
from __future__ import annotations

import json
from pathlib import Path

import pytest


def _write_minimal_pl_tree(root: Path) -> None:
    (root / "config").mkdir(parents=True)
    (root / "data").mkdir(parents=True)
    (root / "templates" / "offers").mkdir(parents=True)
    (root / "templates" / "cloaked").mkdir(parents=True)
    (root / "templates" / "offers" / "expert_review.php").write_text("<?php\n", encoding="utf-8")
    (root / "templates" / "cloaked" / "health_magazine.php").write_text("<?php\n", encoding="utf-8")

    settings = {
        "default_offer_url": "https://test.example/",
        "cloak_url": "https://cloak.test/",
        "db_path": str(root / "data" / "clicks.db"),
        "log_path": str(root / "data" / "errors.log"),
        "cloak_template": "health_magazine",
        "alerts": {"bot_pct_per_hour": 40},
    }
    (root / "config" / "settings.json").write_text(
        json.dumps(settings, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    advertisers = [
        {
            "id": "adv_test",
            "name": "Test Adv",
            "status": "active",
            "url": "https://offer.test/",
            "rate": 1.0,
            "geo": [],
            "device": [],
        }
    ]
    (root / "config" / "advertisers.json").write_text(
        json.dumps(advertisers, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    (root / "config" / "geo_data.json").write_text("{}", encoding="utf-8")
    (root / "config" / "splits.json").write_text("[]", encoding="utf-8")
    (root / "data" / "agent_memory.json").write_text("{}", encoding="utf-8")


def _patch_config_and_config_map(monkeypatch: pytest.MonkeyPatch, root: Path, api_key: str) -> None:
    import internal_api.config as icfg
    import internal_api.routes.configs as cfg_routes

    monkeypatch.setattr(icfg, "ROOT", root)
    monkeypatch.setattr(icfg, "CLICKS_DB", root / "data" / "clicks.db")
    monkeypatch.setattr(icfg, "SETTINGS_JSON", root / "config" / "settings.json")
    monkeypatch.setattr(icfg, "ADVERTISERS_JSON", root / "config" / "advertisers.json")
    monkeypatch.setattr(icfg, "GEO_DATA_JSON", root / "config" / "geo_data.json")
    monkeypatch.setattr(icfg, "SPLITS_JSON", root / "config" / "splits.json")
    monkeypatch.setattr(icfg, "AGENT_MEMORY", root / "data" / "agent_memory.json")
    monkeypatch.setattr(icfg, "SHAVE_REPORT", root / "data" / "shave_report.json")
    monkeypatch.setattr(icfg, "API_KEY", api_key)
    monkeypatch.setattr(icfg, "GIT_AUTOCOMMIT", False)

    monkeypatch.setattr(
        cfg_routes,
        "_CONFIG_MAP",
        {
            "settings": icfg.SETTINGS_JSON,
            "advertisers": icfg.ADVERTISERS_JSON,
            "geo_data": icfg.GEO_DATA_JSON,
            "splits": icfg.SPLITS_JSON,
        },
    )


@pytest.fixture
def pl_client(tmp_path, monkeypatch):
    """Internal API без API-ключа (как dev на VPS)."""
    root = tmp_path / "prelend"
    _write_minimal_pl_tree(root)
    _patch_config_and_config_map(monkeypatch, root, api_key="")

    from internal_api.main import app
    from fastapi.testclient import TestClient

    with TestClient(app) as client:
        yield client


@pytest.fixture
def pl_client_with_key(tmp_path, monkeypatch):
    """Internal API с обязательным X-API-Key."""
    root = tmp_path / "prelend"
    _write_minimal_pl_tree(root)
    _patch_config_and_config_map(monkeypatch, root, api_key="unit-test-api-key-32bytes!!")

    from internal_api.main import app
    from fastapi.testclient import TestClient

    with TestClient(app) as client:
        yield client
