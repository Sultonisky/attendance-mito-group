"""Shared pytest fixtures for the AI service test-suite.

The local developer ``.env`` points ``AI_BIOMETRIC_DATABASE_URL`` at a real
PostgreSQL instance. CI must stay hermetic (in-memory store, fixed API key),
so this conftest pins the same deterministic environment GitHub Actions uses
(see ``.github/workflows/CI.yml`` job ``ai-service``). Pydantic settings are
cached via ``functools.lru_cache`` — clearing the cache AFTER the env vars are
set guarantees every test module observes the CI values regardless of the
developer's local ``.env`` file.
"""

from __future__ import annotations

import os

os.environ["AI_API_KEY"] = "test-secret-key-123"
os.environ["AI_BIOMETRIC_DATABASE_URL"] = ""

from collections.abc import Generator  # noqa: E402

import pytest  # noqa: E402

from app.api import face as face_module  # noqa: E402
from app.core.config import get_settings  # noqa: E402

get_settings.cache_clear()


@pytest.fixture(autouse=True)
def _reset_face_storage() -> Generator[None, None, None]:
    """Reset the global face storage + settings cache between tests.

    ``app.api.face._storage`` is a module-level singleton. Without a reset,
    the first test that touches it pins whatever backend was configured at
    that moment (e.g. a developer-local Postgres), leaking state into later
    tests and making ``create_storage`` / idempotency assertions flaky.
    """
    get_settings.cache_clear()
    face_module._storage = None
    yield
    face_module._storage = None
    get_settings.cache_clear()
