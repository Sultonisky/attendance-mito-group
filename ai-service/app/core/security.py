"""
Security utilities for the FastAPI service.

FastAPI is an internal service called only by Laravel. Protected endpoints
must carry the shared ``X-API-Key`` header configured via the
``AI_API_KEY`` environment variable. The key is never hard-coded — it always
comes from the environment / settings.

The health endpoint (``/health``) is intentionally left unauthenticated so
Laravel's ``/api/v1/ai-health`` probe works without credentials.
"""

from collections.abc import Callable
from typing import Optional

from fastapi import Depends, HTTPException, Request, status
from fastapi.security import APIKeyHeader

from app.core.config import Settings, get_settings

api_key_header = APIKeyHeader(name="X-API-Key", auto_error=False)


async def require_api_key(
    api_key: Optional[str] = Depends(api_key_header),
    settings: Settings = Depends(get_settings),
) -> Settings:
    """Validate the internal service API key for protected endpoints.

    Returns the ``Settings`` object on success so downstream handlers can
    access model configuration. Raises 401 when the key is missing or
    incorrect.
    """
    if api_key is None or api_key != settings.api_key:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid or missing API key.",
        )

    return settings


def auth_middleware(settings: Settings) -> Callable:
    """Build a FastAPI dependency that enforces the API key on a router group."""
    return require_api_key
