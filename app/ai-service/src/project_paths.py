from __future__ import annotations

import os
from pathlib import Path


AI_SERVICE_ROOT = Path(__file__).resolve().parents[1]
WEB_DATA_DIR_ENV = "I4S_WEB_DATA_DIR"
WEB_ROOT_ENV = "I4S_WEB_ROOT"


def resolve_web_data_dir(
    required_file: str | None = None,
    *,
    ai_service_root: Path | None = None,
) -> Path:
    """Resolve Laravel web/data for local monorepo and isolated VPS releases."""
    ai_root = (ai_service_root or AI_SERVICE_ROOT).resolve()
    candidates = _web_data_dir_candidates(ai_root)

    for candidate in candidates:
        if required_file is None and candidate.is_dir():
            return candidate
        if required_file is not None and (candidate / required_file).is_file():
            return candidate

    required = required_file or "<directory>"
    attempted = ", ".join(str(path) for path in candidates)
    raise FileNotFoundError(
        f"Could not resolve Laravel web/data for {required}. "
        f"Set {WEB_DATA_DIR_ENV} or {WEB_ROOT_ENV}. Tried: {attempted}"
    )


def resolve_web_data_file(
    filename: str,
    *,
    ai_service_root: Path | None = None,
) -> Path:
    return resolve_web_data_dir(filename, ai_service_root=ai_service_root) / filename


def _web_data_dir_candidates(ai_root: Path) -> list[Path]:
    candidates: list[Path] = []
    env_data_dir = os.environ.get(WEB_DATA_DIR_ENV)
    env_web_root = os.environ.get(WEB_ROOT_ENV)

    if env_data_dir:
        candidates.append(Path(env_data_dir))
    if env_web_root:
        candidates.append(Path(env_web_root) / "data")

    # Local repo layout: app/ai-service and app/web are siblings.
    candidates.append(ai_root.parent / "web" / "data")

    # VPS layout: domain/app/current and domain/ai-service/current are siblings.
    if len(ai_root.parents) >= 3:
        candidates.append(ai_root.parents[2] / "app" / "current" / "data")

    # Historical helper fallback from the repository root.
    if len(ai_root.parents) >= 2:
        candidates.append(ai_root.parents[1] / "app" / "web" / "data")

    return _unique_paths(candidates)


def _unique_paths(paths: list[Path]) -> list[Path]:
    unique: list[Path] = []
    seen: set[str] = set()
    for path in paths:
        key = str(path)
        if key in seen:
            continue
        seen.add(key)
        unique.append(path)
    return unique
