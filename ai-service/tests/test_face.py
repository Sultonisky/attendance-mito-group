"""
Tests for the FastAPI face AI/CV endpoints.

These tests use TestClient against the actual FastAPI application. They validate:
- API key authentication (missing/invalid/valid)
- Valid image enrollment
- Invalid image rejection (bad format, oversized, empty)
- Verification response schema
- Liveness field presence and safe default
- Malformed request handling
- Exception handling boundary
"""

import io
import os

# Set the API key BEFORE importing app modules so the cached Settings
# picks it up on first access.
os.environ["AI_API_KEY"] = "test-secret-key-123"

import pytest
from fastapi.testclient import TestClient
from PIL import Image

from app.core.config import get_settings
from app.main import app

# Clear the settings cache so the env var above takes effect.
get_settings.cache_clear()

client = TestClient(app)

_TEST_API_KEY = "test-secret-key-123"


def _make_test_image(
    size: tuple[int, int] = (64, 64),
    seed: int = 0,
) -> bytes:
    """Generate a small in-memory PNG image with visual variance.

    ``seed`` controls the pattern offset so different calls produce genuinely
    different pixel content.
    """
    img = Image.new("RGB", size, color=(255, 255, 255))
    for x in range(size[0]):
        for y in range(size[1]):
            img.putpixel(
                (x, y),
                (
                    ((x * 7 + seed * 13) % 256),
                    ((y * 13 + seed * 7) % 256),
                    ((x + y + seed * 3) * 3 % 256),
                ),
            )
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()


def _png_bytes(data: bytes) -> tuple[bytes, str]:
    """Return (bytes, mime_type) for PNG data."""
    return data, "image/png"


class TestHealthEndpoint:
    """/health remains unauthenticated."""

    def test_health_returns_ok(self):
        resp = client.get("/health")
        assert resp.status_code == 200
        body = resp.json()
        assert body["status"] == "ok"
        assert body["service"] == "attendance-ai"


class TestApiKeyAuth:
    """API key is required for protected endpoints."""

    def test_enroll_without_api_key_returns_401(self):
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"employee_id": "EMP001"},
        )
        assert resp.status_code == 401

    def test_enroll_with_invalid_api_key_returns_401(self):
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"employee_id": "EMP001"},
            headers={"X-API-Key": "wrong-key"},
        )
        assert resp.status_code == 401

    def test_enroll_with_valid_api_key_succeeds(self):
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"employee_id": "EMP001"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 200
        body = resp.json()
        assert body["enrolled"] is True
        assert body["model_version"] == "mito-face-v1"
        assert "embedding_reference" in body
        assert body["face_detected"] is True


class TestEnrollFace:
    """Enrollment endpoint behavior."""

    def test_valid_image_enrolls_successfully(self):
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"employee_id": "EMP002"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 200
        body = resp.json()
        assert body["enrolled"] is True
        assert isinstance(body["embedding_reference"], str)
        assert len(body["embedding_reference"]) > 0
        assert body["quality_score"] >= 0.0

    def test_missing_image_returns_422(self):
        resp = client.post(
            "/face/enroll",
            data={"employee_id": "EMP001"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 422

    def test_invalid_mime_type_rejected(self):
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.txt", b"not an image", "text/plain")},
            data={"employee_id": "EMP001"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 400

    def test_non_image_data_rejected(self):
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", b"fake image data", "image/png")},
            data={"employee_id": "EMP001"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 400

    def test_embedding_not_exposed_in_enroll_response(self):
        """Raw embedding vectors must never be returned to the caller."""
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"employee_id": "EMP_EMBED"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        body = resp.json()
        assert "embedding" not in body
        assert "embedding_vector" not in body
        assert "raw_embedding" not in body


class TestVerifyFace:
    """Verification endpoint behavior."""

    @pytest.fixture(autouse=True)
    def _enroll_before_verify(self):
        """Enroll a face first so verify has an embedding to compare."""
        img_bytes, mime = _png_bytes(_make_test_image(seed=42))
        enroll_resp = client.post(
            "/face/enroll",
            files={"image": ("enroll.png", img_bytes, mime)},
            data={"employee_id": "EMP_VERIFY"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert enroll_resp.status_code == 200
        self._embedding_reference = enroll_resp.json()["embedding_reference"]
        yield

    def _verify_data(self, img_bytes, mime):
        return {
            "files": {"image": ("verify.png", img_bytes, mime)},
            "data": {
                "employee_id": "EMP_VERIFY",
                "embedding_reference": self._embedding_reference,
            },
            "headers": {"X-API-Key": _TEST_API_KEY},
        }

    def test_verify_returns_correct_schema(self):
        img_bytes, mime = _png_bytes(_make_test_image(seed=42))
        kwargs = self._verify_data(img_bytes, mime)
        resp = client.post("/face/verify", **kwargs)
        assert resp.status_code == 200
        body = resp.json()
        assert "verified" in body
        assert "confidence" in body
        assert "liveness" in body
        assert "model_version" in body
        assert "face_detected" in body
        assert "processing_time_ms" in body
        assert "quality_score" in body
        assert isinstance(body["verified"], bool)
        assert isinstance(body["confidence"], (int, float))
        assert isinstance(body["liveness"], bool)
        assert isinstance(body["processing_time_ms"], int)

    def test_verify_same_image_returns_high_confidence(self):
        img_bytes, mime = _png_bytes(_make_test_image(seed=42))
        kwargs = self._verify_data(img_bytes, mime)
        resp = client.post("/face/verify", **kwargs)
        assert resp.status_code == 200
        body = resp.json()
        assert body["confidence"] >= 0.9

    def test_verify_face_mismatch_returns_verified_false(self):
        different_img, mime = _png_bytes(_make_test_image(seed=99))
        kwargs = self._verify_data(different_img, mime)
        resp = client.post("/face/verify", **kwargs)
        assert resp.status_code == 200
        body = resp.json()
        assert body["verified"] is False

    def test_verify_liveness_field_is_present(self):
        img_bytes, mime = _png_bytes(_make_test_image(seed=42))
        kwargs = self._verify_data(img_bytes, mime)
        resp = client.post("/face/verify", **kwargs)
        body = resp.json()
        assert "liveness" in body
        assert "liveness_reason" in body

    def test_verify_liveness_is_false_in_dev_mode(self):
        """In disabled liveness mode, liveness must be False (safe default)."""
        img_bytes, mime = _png_bytes(_make_test_image(seed=42))
        kwargs = self._verify_data(img_bytes, mime)
        resp = client.post("/face/verify", **kwargs)
        body = resp.json()
        assert body["liveness"] is False

    def test_verify_unknown_employee_returns_verified_false(self):
        img_bytes, mime = _png_bytes(_make_test_image(seed=7))
        kwargs = self._verify_data(img_bytes, mime)
        # Use a reference that doesn't exist
        kwargs["data"]["embedding_reference"] = "nonexistent-ref"
        resp = client.post("/face/verify", **kwargs)
        assert resp.status_code == 200
        body = resp.json()
        assert body["verified"] is False
        assert body["confidence"] == 0.0

    def test_verify_invalid_image_rejected(self):
        resp = client.post(
            "/face/verify",
            files={"image": ("bad.png", b"not an image", "image/png")},
            data={"employee_id": "EMP_VERIFY"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 400

    def test_verify_response_does_not_expose_embedding(self):
        """Raw embeddings must never be returned in verification responses."""
        img_bytes, mime = _png_bytes(_make_test_image(seed=42))
        kwargs = self._verify_data(img_bytes, mime)
        resp = client.post("/face/verify", **kwargs)
        body = resp.json()
        assert "embedding" not in body
        assert "embedding_vector" not in body
        assert "raw_embedding" not in body


class TestMalformedRequest:
    """Malformed multipart requests return clear errors."""

    def test_verify_missing_image_returns_422(self):
        resp = client.post(
            "/face/verify",
            data={"employee_id": "EMP001"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 422

    def test_verify_missing_employee_id_returns_422(self):
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/verify",
            files={"image": ("test.png", img_bytes, mime)},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 422


class TestExceptionHandling:
    """Unhandled exceptions never leak stack traces."""

    def test_500_response_does_not_leak_traceback(self):
        from app.api import face as face_module

        original = face_module._get_processor

        def _boom(settings):
            raise RuntimeError("internal secret leak")

        face_module._get_processor = _boom
        try:
            img_bytes, mime = _png_bytes(_make_test_image())
            resp = client.post(
                "/face/verify",
                files={"image": ("test.png", img_bytes, mime)},
                data={
                    "employee_id": "EMP_VERIFY",
                    "embedding_reference": "nonexistent-ref",
                },
                headers={"X-API-Key": _TEST_API_KEY},
            )
            assert resp.status_code == 500
            body = resp.json()
            assert "internal secret leak" not in str(body)
            assert body.get("detail") == "Face verification processing failed."
        finally:
            face_module._get_processor = original
