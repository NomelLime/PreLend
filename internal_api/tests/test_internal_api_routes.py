"""Изолированные HTTP-тесты PreLend Internal API (без сети, tmp-файлы)."""
from __future__ import annotations

import json


def test_health_returns_ok(pl_client):
    r = pl_client.get("/health")
    assert r.status_code == 200, r.text
    data = r.json()
    assert data["status"] == "ok"
    assert "db_exists" in data


def test_get_config_settings(pl_client):
    r = pl_client.get("/config/settings")
    assert r.status_code == 200, r.text
    data = r.json()
    assert data["cloak_template"] == "health_magazine"
    assert "default_offer_url" in data


def test_get_config_advertisers(pl_client):
    r = pl_client.get("/config/advertisers")
    assert r.status_code == 200, r.text
    data = r.json()
    assert isinstance(data, list)
    assert data[0]["id"] == "adv_test"


def test_get_templates_lists_stems(pl_client):
    r = pl_client.get("/templates")
    assert r.status_code == 200, r.text
    data = r.json()
    assert "expert_review" in data.get("offers", [])
    assert "health_magazine" in data.get("cloaked", [])


def test_put_settings_roundtrip_cloak_template(pl_client):
    r = pl_client.get("/config/settings")
    base = r.json()
    base["cloak_template"] = "expert_review"
    r2 = pl_client.put("/config/settings?source=pytest", json=base)
    assert r2.status_code == 200, r2.text
    r3 = pl_client.get("/config/settings")
    assert r3.json()["cloak_template"] == "expert_review"


def test_put_settings_rejects_unknown_top_level_key(pl_client):
    r = pl_client.get("/config/settings")
    bad = dict(r.json())
    bad["totally_unknown_key_xyz"] = 1
    r2 = pl_client.put("/config/settings?source=pytest", json=bad)
    assert r2.status_code == 400, r2.text
    assert "Недопустимые ключи" in r2.json()["detail"] or "unknown" in r2.json()["detail"].lower()


def test_get_metrics_structure(pl_client):
    r = pl_client.get("/metrics?period_hours=24")
    assert r.status_code == 200, r.text
    data = r.json()
    assert "period_hours" in data
    assert "geo_breakdown" in data
    assert isinstance(data["geo_breakdown"], list)


def test_get_agents_returns_list(pl_client):
    r = pl_client.get("/agents")
    assert r.status_code == 200, r.text
    assert isinstance(r.json(), list)


def test_auth_required_when_api_key_set(pl_client_with_key):
    r = pl_client_with_key.get("/config/settings")
    assert r.status_code == 403, r.text

    r_ok = pl_client_with_key.get(
        "/config/settings",
        headers={"X-API-Key": "unit-test-api-key-32bytes!!"},
    )
    assert r_ok.status_code == 200, r_ok.text


def test_auth_wrong_key_rejected(pl_client_with_key):
    r = pl_client_with_key.get(
        "/config/settings",
        headers={"X-API-Key": "wrong-key"},
    )
    assert r.status_code == 403, r.text
