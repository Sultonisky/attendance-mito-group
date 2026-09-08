from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Application settings loaded from environment variables (AI_ prefix)."""

    model_config = SettingsConfigDict(env_prefix="AI_", env_file=".env", extra="ignore")

    app_name: str = "attendance-ai"
    environment: str = "local"
    debug: bool = True


@lru_cache
def get_settings() -> Settings:
    return Settings()
