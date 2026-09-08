from typing import Annotated

from fastapi import APIRouter, Depends

from app.core.config import Settings, get_settings

router = APIRouter()


@router.get("/health")
def health(settings: Annotated[Settings, Depends(get_settings)]) -> dict:
    """Infrastructure health endpoint for the AI/CV service."""
    return {
        "status": "ok",
        "service": settings.app_name,
    }
