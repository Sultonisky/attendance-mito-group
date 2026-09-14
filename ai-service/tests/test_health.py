import os

# Ensure a known API key is set before importing the app.
os.environ.setdefault("AI_API_KEY", "test-secret-key-123")

from fastapi.testclient import TestClient

from app.core.config import get_settings
from app.main import app

get_settings.cache_clear()

client = TestClient(app)


def test_health_endpoint_returns_ok():
    response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {
        "status": "ok",
        "service": "attendance-ai",
    }


def test_unknown_endpoint_returns_404():
    response = client.get("/does-not-exist", headers={"X-API-Key": "test-secret-key-123"})

    assert response.status_code == 404
