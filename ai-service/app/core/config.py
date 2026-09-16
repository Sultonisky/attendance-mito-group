from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Application settings loaded from environment variables (AI_ prefix).

    FastAPI is an internal-only AI/CV service. It NEVER receives authoritative
    employee identity or attendance decisions from the caller — Laravel resolves
    and validates those and forwards only the context FastAPI needs to return
    AI facts.
    """

    model_config = SettingsConfigDict(env_prefix="AI_", env_file=".env", extra="ignore")

    app_name: str = "attendance-ai"
    environment: str = "local"
    debug: bool = True

    # ------------------------------------------------------------------
    # Internal service authentication
    # ------------------------------------------------------------------
    # Shared secret used by Laravel to authenticate FastAPI requests.
    # Loaded from the environment; never hard-coded. On the Laravel side this
    # maps to FASTAPI_API_KEY (see backend .env.example).
    api_key: str = "dev-only-change-me"

    # ------------------------------------------------------------------
    # Face / liveness model configuration
    # ------------------------------------------------------------------
    # Pipeline version string identifying the complete model stack
    # (detector + embedding + liveness + preprocessing).
    # Returned with every AI result so Laravel can store it for traceability.
    model_version: str = "mito-face-v1"

    # Directory containing ONNX model assets.
    # Relative paths are resolved from the application working directory.
    model_dir: Path = Path("models")

    # ------------------------------------------------------------------
    # Image validation
    # ------------------------------------------------------------------
    # Maximum accepted image size in bytes (default 5 MB). Prevents unbounded
    # uploads from exhausting server memory during decode.
    max_image_size_bytes: int = 5_000_000

    # Maximum image dimensions FastAPI will decode. Guards against decompression
    # bombs (e.g. tiny compressed files that expand to huge pixel buffers).
    max_image_dimension: int = 4096

    # Confidence threshold (0.0 - 1.0) at which the development adapter considers
    # two embeddings a match. This is NOT a production business rule — it only
    # bounds the dev adapter. The final attendance decision and any configurable
    # business threshold live in Laravel.
    similarity_threshold: float = 0.6

    # Liveness handling mode:
    #   "disabled"  — liveness always returns false (safe default; the boundary
    #                 exists but cannot pass without a real liveness model).
    #   "dev"       — liveness is simulated as true ONLY when the development
    #                 adapter confirms basic image diversity. Never use in
    #                 production.
    #
    # Production deployments must use a capability-3 (spoof-aware) liveness model.
    liveness_mode: str = "disabled"

    # ------------------------------------------------------------------
    # Biometric storage (AI-3)
    # ------------------------------------------------------------------
    # Dedicated PostgreSQL connection for biometric embedding storage.
    # FastAPI is the sole accessor. Laravel never connects to this database.
    # If not set, FastAPI falls back to in-memory storage (development only).
    biometric_database_url: str | None = None


@lru_cache
def get_settings() -> Settings:
    return Settings()
