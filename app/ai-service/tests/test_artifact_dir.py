"""Tests for resolve_artifact_dir / model profile artifact_dir resolution.

Run from ``app/ai-service`` with the public requirements installed:

    PYTHONPATH=public_runtime python -m pytest tests/test_artifact_dir.py -q
"""

import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "public_runtime"))

from services import service_predict  # noqa: E402


@pytest.fixture()
def isolated_root(monkeypatch, tmp_path):
    """Point the module at an empty fake service root and clear the override."""
    monkeypatch.setattr(service_predict, "AI_SERVICE_ROOT", tmp_path)
    monkeypatch.delenv(service_predict.MODEL_ARTIFACT_DIR_ENV, raising=False)
    return tmp_path


def _make_checkout_layout(root: Path) -> Path:
    target = root / "model-artifacts" / "gpt41"
    target.mkdir(parents=True)
    return target


def _make_docker_layout(root: Path) -> Path:
    target = root / "trained_classifier" / "new_format" / "gpt41"
    target.mkdir(parents=True)
    return target


def test_docker_layout_wins_when_present(isolated_root):
    docker_dir = _make_docker_layout(isolated_root)
    _make_checkout_layout(isolated_root)

    assert service_predict.resolve_artifact_dir() == docker_dir


def test_checkout_layout_used_when_docker_layout_absent(isolated_root):
    checkout_dir = _make_checkout_layout(isolated_root)

    assert service_predict.resolve_artifact_dir() == checkout_dir


def test_explicit_override_wins_over_existing_layouts(isolated_root, monkeypatch, tmp_path):
    _make_docker_layout(isolated_root)
    _make_checkout_layout(isolated_root)
    override_dir = tmp_path / "somewhere" / "else"
    override_dir.mkdir(parents=True)
    monkeypatch.setenv(service_predict.MODEL_ARTIFACT_DIR_ENV, str(override_dir))

    assert service_predict.resolve_artifact_dir() == override_dir.resolve()


def test_missing_override_is_returned_unchanged_for_fail_closed_validation(
    isolated_root, monkeypatch
):
    missing = isolated_root / "does" / "not" / "exist"
    monkeypatch.setenv(service_predict.MODEL_ARTIFACT_DIR_ENV, str(missing))

    assert service_predict.resolve_artifact_dir() == missing.resolve()


def test_neither_layout_present_returns_canonical_docker_path(isolated_root):
    canonical = isolated_root / "trained_classifier" / "new_format" / "gpt41"

    assert service_predict.resolve_artifact_dir() == canonical


def test_model_profile_artifact_dir_matches_resolver():
    """MODEL_PROFILES is built at import time; it must agree with the resolver.

    In a bare checkout both resolve to model-artifacts/gpt41; in the Docker
    image both resolve to trained_classifier/new_format/gpt41.
    """
    profile = service_predict.MODEL_PROFILES[service_predict.PUBLIC_MODEL_PROFILE]

    assert profile.artifact_dir == service_predict.resolve_artifact_dir()
    assert profile.artifact_dir.is_absolute()
    assert profile.artifact_path("sector_columns.pkl") == (
        profile.artifact_dir / "sector_columns.pkl"
    )


def test_relative_override_is_anchored_to_service_root(isolated_root, monkeypatch, tmp_path):
    checkout_dir = _make_checkout_layout(isolated_root)
    elsewhere = tmp_path / "unrelated-cwd"
    elsewhere.mkdir()
    monkeypatch.chdir(elsewhere)
    monkeypatch.setenv(service_predict.MODEL_ARTIFACT_DIR_ENV, "model-artifacts/gpt41")

    assert service_predict.resolve_artifact_dir() == checkout_dir.resolve()
